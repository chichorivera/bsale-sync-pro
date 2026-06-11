# Bsale Sync Pro — Especificación del Plugin WooCommerce

## Resumen

Plugin WordPress/WooCommerce que integra Bsale (ERP/facturación chileno) con una tienda WooCommerce. Cubre emisión de documentos tributarios, sincronización de stock vía webhook y verificaciones de disponibilidad en tiempo real.

---

## Alcance funcional

| Módulo | Descripción |
|---|---|
| **Configuración** | Panel en WooCommerce con todos los parámetros conectados a la API de Bsale |
| **Emisión de documentos** | Genera boleta o factura en Bsale al procesar un pedido |
| **Sincronización de stock** | Endpoint REST que recibe webhooks de Bsale y actualiza stock en WooCommerce |
| **Micro-verificación** | Consulta stock real en Bsale al agregar al carrito y antes del checkout |
| **Mapeo de productos** | Vincula cada producto/variación WooCommerce con su `variantId` de Bsale |

---

## API de Bsale — Referencia base

- **Base URL:** `https://api.bsale.io/v1/`
- **Auth:** Header `access_token: {token}` en cada request
- **Content-Type:** `application/json` en POST/PUT
- **Fechas:** Unix timestamp GMT (no ISO 8601)
- **Paginación:** `?limit=25&offset=0` (máximo 50 por página)

### Endpoints utilizados

| Método | Endpoint | Uso |
|---|---|---|
| GET | `/document_types.json` | Cargar tipos de documento en configuración |
| GET | `/price_lists.json` | Cargar listas de precio en configuración |
| GET | `/offices.json` | Cargar bodegas en configuración |
| GET | `/clients.json?code={rut}` | Buscar cliente por RUT |
| POST | `/clients.json` | Crear cliente si no existe |
| POST | `/documents.json` | Emitir boleta o factura |
| GET | `/stocks.json?variantid={id}&officeid={id}` | Consultar stock de una variante |

---

## Módulo 1 — Configuración

### Ubicación
`WooCommerce > Bsale Sync Pro` (submenú)

### Tabs del panel

#### Tab: Conexión
| Campo | Tipo | Descripción |
|---|---|---|
| `access_token` | Text (password) | Token de API de Bsale |
| — | Botón "Verificar conexión" | Hace GET a `/document_types.json` y muestra OK/error |

#### Tab: Documentos
| Campo | Tipo | Descripción |
|---|---|---|
| Tipo de boleta | Select dinámico | Lista cargada desde `/document_types.json` — guarda `documentTypeId` |
| Tipo de factura | Select dinámico | Lista cargada desde `/document_types.json` — guarda `documentTypeId` |
| Documento a emitir en venta | Select dinámico | Mismo listado, define cuál se emite por defecto en cada pedido |
| Lista de precio | Select dinámico | Cargada desde `/price_lists.json` — guarda `priceListId` |
| Bodega activa | Select dinámico | Cargada desde `/offices.json` — guarda `officeId` |
| Reducir stock al emitir | Checkbox | Si activo, envía `dispatch: 1` en el documento |

> Los selects dinámicos se cargan vía AJAX al abrir el tab (solo si hay `access_token` guardado). Se almacenan en caché transitoria de 1 hora (`bsale_cache_{endpoint}`).

#### Tab: Mapeo de campos
| Campo | Tipo | Descripción |
|---|---|---|
| Campo RUT cliente | Select | Lista de campos WooCommerce billing disponibles. Seleccionar cuál contiene el RUT (ej. `billing_rut`) |
| Campo tipo documento | Select | Campo WC que indica si el cliente quiere boleta o factura (ej. `billing_document_type`) |
| Valor = boleta | Text | Valor del campo anterior que representa boleta (ej. `boleta`) |
| Valor = factura | Text | Valor del campo anterior que representa factura (ej. `factura`) |

#### Tab: Webhook
| Campo | Tipo | Descripción |
|---|---|---|
| URL del webhook | Solo lectura | `{site_url}/wp-json/bsale/v1/stock` — copiar y registrar en Bsale |
| Clave secreta | Text (generado) | HMAC secret para verificar firma del webhook |
| Último evento recibido | Info | Fecha y hora del último webhook procesado |
| Log de eventos | Textarea | Últimos 50 eventos (fecha, variantId, stock anterior → nuevo) |

---

## Módulo 2 — Emisión de documentos

### Trigger
`woocommerce_order_status_processing` (pedido pasa a "procesando")

### Flujo
```
Pedido procesando
  └─ ¿Ya tiene _bsale_document_id? → sí: salir (no duplicar)
       └─ no: continuar
           ├─ Leer tipo de documento desde meta del pedido (boleta/factura)
           ├─ Resolver documentTypeId según configuración
           ├─ Buscar o crear cliente en Bsale (GET por RUT → POST si no existe)
           ├─ Construir payload del documento
           ├─ POST /v1/documents.json
           ├─ Éxito: guardar _bsale_document_id en meta del pedido
           └─ Error: guardar _bsale_document_error + loguear
```

### Payload del documento

```json
{
  "documentTypeId": "{config}",
  "officeId": "{config}",
  "priceListId": "{config}",
  "emissionDate": "{unix_timestamp}",
  "expirationDate": "{unix_timestamp}",
  "declareSii": 1,
  "dispatch": 1,
  "salesId": "{order_id}",
  "sendEmail": 0,
  "client": {
    "code": "{billing_rut}",
    "firstName": "{billing_first_name}",
    "lastName": "{billing_last_name}",
    "email": "{billing_email}",
    "company": "{billing_company}",
    "address": "{billing_address_1}",
    "municipality": "{billing_state_name}",
    "city": "{billing_city}",
    "activity": "Sin giro",
    "companyOrPerson": 0
  },
  "details": [
    {
      "variantId": "{_bsale_variant_id del producto}",
      "netUnitValue": "{precio_neto}",
      "quantity": "{qty}",
      "taxId": "[{taxId_config}]",
      "discount": "{descuento_porcentaje}",
      "comment": "{nombre_producto}"
    }
  ],
  "payments": [
    {
      "paymentTypeId": 1,
      "amount": "{order_total}",
      "recordDate": "{unix_timestamp}"
    }
  ]
}
```

### Notas críticas
- `salesId` = order_id previene documentos duplicados si el webhook se llama dos veces
- `netUnitValue` = precio con IVA incluido: `round($price / 1.19, 4)` para productos con IVA 19%
- `municipality` debe ser **nombre completo** de la región/comuna, no código (`CL-RM` causa error `cli_004`)
- Si el `variantId` no está mapeado en un ítem, omitir ese detail o usar `comment` + `netUnitValue` sin `variantId`
- Para boleta: `companyOrPerson: 0` y `code` del cliente es opcional (puede ir vacío o RUT personal)
- Para factura: `companyOrPerson: 1`, `code` (RUT empresa) y `company` son obligatorios

### Meta del pedido guardada
| Meta key | Valor |
|---|---|
| `_bsale_document_id` | ID del documento en Bsale |
| `_bsale_document_url` | URL PDF del documento |
| `_bsale_document_type` | `boleta` o `factura` |
| `_bsale_document_error` | Mensaje de error si falló |
| `_bsale_emission_date` | Timestamp de emisión |

---

## Módulo 3 — Sincronización de stock vía Webhook

### Endpoint registrado
```
POST https://{site}/wp-json/bsale/v1/stock
```

Registrado con `register_rest_route` en el namespace `bsale/v1`.

### Seguridad del endpoint
1. Verificar que el request viene de Bsale (whitelist de IPs de Bsale o HMAC secret si Bsale lo soporta)
2. Validar que `topic` del payload = `stock`
3. Responder `200 OK` siempre (para que Bsale no reintente), procesar en background con `wp_schedule_single_event`

### Payload entrante de Bsale (webhook stock)
```json
{
  "cpnId": 123,
  "resourceId": 44977,
  "resource": "https://api.bsale.io/v1/stocks/44977.json",
  "topic": "stock",
  "action": "PUT",
  "send": 1718200000
}
```

### Flujo de procesamiento
```
POST recibido
  └─ Validar campos mínimos (resourceId, topic = stock)
      └─ Responder 200 inmediatamente
          └─ wp_schedule_single_event → bsale_process_stock_webhook
              ├─ GET /v1/stocks/{resourceId}.json → obtener {variantId, quantityAvailable}
              ├─ Buscar producto WC donde meta _bsale_variant_id = variantId
              ├─ ¿Encontrado?
              │   ├─ sí: wc_update_product_stock($product, $quantityAvailable)
              │   └─ no: loguear "variantId {X} no mapeado en WooCommerce"
              └─ Guardar en log de eventos del webhook
```

### Campo de mapeo en producto
Cada producto simple o variación de WooCommerce tiene un campo en su página de edición:
- **Meta key:** `_bsale_variant_id`
- **UI:** Campo "Bsale Variant ID" en tab "General" (producto simple) o en cada variación
- Se puede poblar manualmente o mediante un futuro importador

---

## Módulo 4 — Micro-verificación de stock

### Contexto
Consulta el stock **real en Bsale** (no el de WooCommerce) para evitar vender sin stock entre sincronizaciones.

### Verificación 1: Al agregar al carrito

**Hook:** `woocommerce_add_to_cart_validation`

```
Antes de agregar al carrito
  └─ Obtener _bsale_variant_id del producto/variación
      └─ ¿Tiene variantId?
          ├─ no: permitir (no bloquear si no está mapeado)
          └─ sí: GET /v1/stocks.json?variantid={id}&officeid={officeId}
              ├─ quantityAvailable >= qty pedida → permitir
              └─ quantityAvailable < qty pedida → wc_add_notice(error) + return false
```

**Cache:** Resultado cacheado en transient `bsale_stock_{variantId}_{officeId}` por **60 segundos** para no saturar la API.

### Verificación 2: Antes del checkout

**Hook:** `woocommerce_check_cart_items`

Recorre todos los ítems del carrito y aplica la misma lógica. Si algún ítem no tiene stock suficiente, agrega un notice de error e impide avanzar al checkout.

### Mensajes de error (filtrables)
```php
apply_filters('bsale_stock_error_message', 
  sprintf(__('El producto "%s" no tiene stock suficiente en este momento.', 'bsale-sync-pro'), $product_name),
  $product, $qty_requested, $qty_available
);
```

---

## Estructura de archivos del plugin

```
bsale-sync-pro/
├── bsale-sync-pro.php              # Bootstrap: define constantes, carga autoloader, registra hooks principales
├── uninstall.php                   # Limpia opciones y transients al desinstalar
├── includes/
│   ├── class-bsale-api.php         # Cliente HTTP: todos los calls a api.bsale.io
│   ├── class-bsale-settings.php    # Panel de configuración WooCommerce
│   ├── class-bsale-documents.php   # Emisión de boleta/factura en pedidos
│   ├── class-bsale-stock-sync.php  # Webhook endpoint + procesamiento de stock
│   ├── class-bsale-stock-check.php # Micro-verificaciones al carrito y checkout
│   └── class-bsale-product-meta.php# Campo _bsale_variant_id en productos/variaciones
└── assets/
    ├── js/
    │   └── bsale-admin.js          # Carga dinámica de selects en configuración
    └── css/
        └── bsale-admin.css
```

---

## Clase: Bsale_API

Responsabilidad única: comunicarse con `api.bsale.io`. Todas las demás clases la usan.

```php
class Bsale_API {
    private string $token;
    private string $base_url = 'https://api.bsale.io/v1/';

    public function get(string $endpoint, array $params = []): array|WP_Error
    public function post(string $endpoint, array $body): array|WP_Error
    public function get_document_types(): array|WP_Error
    public function get_price_lists(): array|WP_Error
    public function get_offices(): array|WP_Error
    public function get_client_by_rut(string $rut): array|null|WP_Error
    public function create_client(array $data): array|WP_Error
    public function create_document(array $data): array|WP_Error
    public function get_stock(int $variant_id, int $office_id): array|WP_Error
}
```

- Todos los métodos retornan array con la respuesta o `WP_Error`
- Loguea errores HTTP en `error_log` con contexto
- Rate limiting suave: si recibe 429, espera 1s y reintenta una vez

---

## Opciones guardadas en WordPress

Todas bajo la clave `bsale_sync_pro_settings` (array serializado):

```php
[
  'access_token'          => '',
  'boleta_doc_type_id'    => '',
  'factura_doc_type_id'   => '',
  'default_doc_type_id'   => '',
  'price_list_id'         => '',
  'office_id'             => '',
  'dispatch_on_emit'      => true,
  'rut_field'             => 'billing_rut',
  'doc_type_field'        => 'billing_document_type',
  'boleta_value'          => 'boleta',
  'factura_value'         => 'factura',
  'webhook_secret'        => '',  // generado al activar el plugin
]
```

---

## Seguridad

- `access_token` mostrado como campo password en el admin
- Nonce en todos los AJAX admin
- Webhook endpoint: validar `Content-Type: application/json` + IP whitelist o secret header
- Inputs sanitizados con `sanitize_text_field` / `absint` / `wc_clean`
- Capacidad requerida `manage_woocommerce` para ver el panel de configuración
- `salesId` en documentos previene duplicados en Bsale

---

## Eventos y logs

El plugin escribe en una tabla de logs liviana (WP option con array de últimos 100 eventos) visible desde el panel de configuración. Cada entrada:

```
[2025-06-11 14:32:01] DOCUMENT_CREATED  order_id=1234  bsale_id=8135  type=boleta
[2025-06-11 14:31:55] STOCK_UPDATED     variant_id=157  wc_product=567  stock=12
[2025-06-11 14:31:40] STOCK_CHECK_FAIL  variant_id=157  requested=3  available=1  product="Polera L"
[2025-06-11 14:30:10] WEBHOOK_RECEIVED  resourceId=44977  action=PUT
```

---

## Dependencias y compatibilidad

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- El campo RUT debe existir en el tema (Wayka lo provee como `billing_rut` o similar — configurable)
- No requiere librerías externas (usa `wp_remote_*`)

---

## Fases de desarrollo

### Fase 1 — Base
- [ ] Estructura de archivos y bootstrap del plugin
- [ ] `Bsale_API` — métodos `get` y `post` con manejo de errores
- [ ] Panel de configuración Tab: Conexión + verificación de token

### Fase 2 — Configuración dinámica
- [ ] AJAX para cargar document_types, price_lists, offices
- [ ] Tab: Documentos con selects dinámicos
- [ ] Tab: Mapeo de campos

### Fase 3 — Emisión de documentos
- [ ] `Bsale_Documents` — hook en order processing
- [ ] Construir payload + buscar/crear cliente
- [ ] Guardar meta en pedido + manejo de errores

### Fase 4 — Stock
- [ ] `Bsale_Product_Meta` — campo variantId en productos/variaciones
- [ ] `Bsale_Stock_Sync` — endpoint REST + procesamiento async
- [ ] Tab: Webhook en configuración

### Fase 5 — Micro-verificaciones
- [ ] `Bsale_Stock_Check` — validación al agregar al carrito
- [ ] Validación antes del checkout
- [ ] Cache de 60s en transients

### Fase 6 — Logs y pulido
- [ ] Sistema de logs en panel admin
- [ ] `uninstall.php`
- [ ] Tests manuales end-to-end
