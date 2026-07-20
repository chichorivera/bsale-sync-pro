# Bsale Sync Pro

Plugin WordPress/WooCommerce que conecta tu tienda con **Bsale**, el ERP chileno. Emite boletas y facturas automáticamente, mantiene el stock sincronizado en tiempo real y bloquea ventas sin stock antes de que ocurran.

---

## ¿Qué hace?

| Módulo | Descripción |
|---|---|
| 🧾 **Emisión de documentos** | Genera boleta o factura en Bsale cuando un pedido pasa a "Procesando" |
| 📦 **Sincronización de stock** | Recibe webhooks de Bsale y actualiza el stock en WooCommerce al instante. Activa la gestión de inventario automáticamente. |
| 💲 **Sincronización de precios** | Recibe webhooks de Bsale y actualiza el precio regular del producto en WooCommerce cuando cambia en la lista de precios configurada |
| 🔍 **Verificación en tiempo real** | Consulta el stock real en Bsale al agregar al carrito y antes del checkout |
| 🚨 **Sincronización masiva (SOS)** | Actualiza stock y/o precios de todos los productos WooCommerce desde Bsale en un clic |

---

## Cómo funciona el match de productos

El plugin conecta productos de WooCommerce con variantes de Bsale usando el **SKU**. No requiere configuración adicional por producto.

```
SKU en WooCommerce  ←→  code de la variante en Bsale
```

- Si un producto **tiene SKU** y existe una variante con ese código en Bsale → se sincroniza el stock y se incluye el `variantId` en los documentos emitidos.
- Si un producto **no tiene SKU** → se ignora silenciosamente (la venta no se bloquea).

---

## Requisitos

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 8.0+
- Cuenta Bsale con access token
- Productos con SKU en WooCommerce que coincidan con los códigos en Bsale

---

## Instalación

1. Descarga o clona este repositorio en `wp-content/plugins/bsale-sync-pro`
2. Activa el plugin desde **Plugins → Plugins instalados**
3. Ve a **WooCommerce → Bsale Sync Pro** y configura tu token de API

---

## Configuración

El panel tiene 4 pestañas:

### Conexión
Ingresa tu **Access Token** de Bsale (`Configuración → API → Access Token`) y verifica la conexión.

### Documentos
Asocia los tipos de documento de Bsale, la lista de precio y la bodega activa. Los selects se cargan dinámicamente desde tu cuenta Bsale.

### Mapeo de campos
Indica qué campos del pedido WooCommerce contienen el RUT, el tipo de documento (boleta/factura) y el giro comercial.

```
Campo tipo documento  →  ej. billing_document_type
Valor boleta          →  ej. boleta
Valor factura         →  ej. factura
Campo RUT (boleta)    →  ej. billing_rut
Campo RUT (factura)   →  ej. billing_company_rut
```

### Webhook
Copia la URL generada y regístrala en **Bsale → Configuración → Webhooks** para los topics **Stock** y **Precio**.

### Sincronización (SOS)
Botones de sincronización masiva para situaciones de emergencia o primera puesta en marcha:

- **Sincronizar stock** — recorre todos los productos WooCommerce con SKU, consulta el stock disponible en Bsale para la bodega configurada y lo actualiza en WooCommerce. Para productos variables solo procesa las variaciones.
- **Sincronizar precios** — busca cada SKU en Bsale, obtiene el precio con IVA de la lista configurada y actualiza el precio regular en WooCommerce.

Ambas operaciones procesan en lotes de 15 SKUs con barra de progreso en tiempo real. El informe final indica qué SKUs se sincronizaron, cuáles no existen en Bsale y si hubo errores.

El campo **Clave secreta** es editable: puedes pegar una clave existente o usar el botón **Regenerar** para crear una nueva. Si regeneras, recuerda actualizar la URL en Bsale.

---

## Columnas en listado de pedidos

El plugin agrega dos columnas al listado `/wp-admin/admin.php?page=wc-orders`:

- **Bsale** — ícono PDF con link al documento emitido, o indicador de estado/error
- **Envío** — badge clickeable que cicla entre `Por enviar → Enviado → Entregado`

---

## Metas guardadas por pedido

| Meta key | Contenido |
|---|---|
| `_bsale_document_id` | ID del documento en Bsale |
| `_bsale_document_url` | URL del PDF |
| `_bsale_document_type` | `boleta` o `factura` |
| `_bsale_document_error` | Mensaje de error (si falló) |
| `_bsale_emission_date` | Timestamp de emisión |
| `_shipping_status` | `pending` / `shipped` / `delivered` |

---

## Estructura del plugin

```
bsale-sync-pro/
├── bsale-sync-pro.php              # Bootstrap
├── uninstall.php                   # Limpieza al desinstalar
├── includes/
│   ├── class-bsale-api.php         # Cliente HTTP → api.bsale.io
│   ├── class-bsale-settings.php    # Panel de configuración (4 tabs)
│   ├── class-bsale-documents.php   # Emisión de documentos
│   ├── class-bsale-stock-sync.php  # Webhook REST + procesamiento async
│   ├── class-bsale-stock-check.php # Verificación al carrito y checkout
│   └── class-bsale-order-columns.php # Columnas en listado de pedidos
├── log/
│   ├── .htaccess                   # Bloquea acceso web directo
│   └── sales-YYYY-MM-DD.log        # Generado cuando el log de ventas está activo
└── assets/
    ├── js/bsale-admin.js
    └── css/bsale-admin.css
```

---

## Notas técnicas

- **Match por SKU**: el campo `code` de cada variante en Bsale debe coincidir exactamente con el SKU del producto en WooCommerce
- **Descuentos explícitos**: cada línea del documento muestra el precio original y el porcentaje de descuento efectivo, cubriendo precios de oferta, cupones y combinaciones de ambos
- **Costo de envío**: se agrega automáticamente como línea separada en el documento cuando el envío tiene costo mayor a cero. El retiro en tienda y envío gratis se omiten.
- **Webhook unificado**: el mismo endpoint maneja los topics `stock` y `price` de Bsale. Solo se procesan precios si la lista coincide con la configurada en el panel
- **Gestión de inventario automática**: al sincronizar stock, activa `manage_stock` en el producto WooCommerce si no estaba habilitado
- **Sin llamadas extra**: el stock se consulta directo con `GET /stocks.json?code={sku}&officeid={id}` y los detalles del documento usan `code` (SKU) en vez de `variantId` — sin pasos intermedios
- **Sincronización masiva en lotes**: el tab Sincronización procesa 15 SKUs por lote AJAX secuencial — maneja catálogos de miles de productos sin agotar el tiempo de ejecución PHP
- **Precios con IVA**: la sync masiva usa `variantValueWithTaxes` de Bsale (precio con IVA) para actualizar el precio regular en WooCommerce, consistente con la convención de precios chilenos
- **Solo registros activos**: los selects de configuración filtran con `state=0` (activo en la convención de Bsale)
- **Anti-duplicado**: `salesId = order_id` en cada documento previene emisiones dobles
- **HPOS compatible**: funciona con almacenamiento clásico y con el nuevo HPOS de WooCommerce 7.1+
- **Fail-open**: si la API no responde o el producto no tiene SKU, las ventas continúan sin interrupciones
- **Caché**: stock real cacheado 60 segundos por SKU+bodega
- **Log de eventos**: últimos 100 eventos visibles en la pestaña Webhook del panel
- **Log de ventas en archivo**: activable desde la pestaña Documentos — guarda request y response JSON de cada venta en `log/sales-YYYY-MM-DD.log`, protegido por `.htaccess`
- **Actualización de cliente sin nombre**: si el cliente ya existe en Bsale pero fue creado sin nombre, se actualiza automáticamente antes de emitir el documento

---

## Estructura del directorio `log/`

```
log/
├── .htaccess          # Bloquea acceso web directo
├── .gitkeep
└── sales-2026-06-18.log   # Generado al activar el log de ventas
```

Cada entrada tiene este formato:

```
[2026-06-18 12:34:56] ORDER#42 REQUEST:
{ ... payload enviado a Bsale ... }

[2026-06-18 12:34:57] ORDER#42 RESPONSE:
{ ... respuesta de Bsale ... }
```

---

## Versión

`2.0.0` — Desarrollado para tiendas WooCommerce chilenas con facturación electrónica Bsale.
