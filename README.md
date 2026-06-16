# Bsale Sync Pro

Plugin WordPress/WooCommerce que conecta tu tienda con **Bsale**, el ERP chileno. Emite boletas y facturas automáticamente, mantiene el stock sincronizado en tiempo real y bloquea ventas sin stock antes de que ocurran.

---

## ¿Qué hace?

| Módulo | Descripción |
|---|---|
| 🧾 **Emisión de documentos** | Genera boleta o factura en Bsale cuando un pedido pasa a "Procesando" |
| 📦 **Sincronización de stock** | Recibe webhooks de Bsale y actualiza el stock en WooCommerce al instante |
| 🔍 **Verificación en tiempo real** | Consulta el stock real en Bsale al agregar al carrito y antes del checkout |

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
Copia la URL generada y regístrala en **Bsale → Configuración → Webhooks → Stock**. El plugin usa una clave secreta para validar cada notificación.

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
└── assets/
    ├── js/bsale-admin.js
    └── css/bsale-admin.css
```

---

## Notas técnicas

- **Match por SKU**: el campo `code` de cada variante en Bsale debe coincidir exactamente con el SKU del producto en WooCommerce
- **Sin llamadas extra**: el stock se consulta directo con `GET /stocks.json?code={sku}&officeid={id}` y los detalles del documento usan `code` (SKU) en vez de `variantId` — sin pasos intermedios
- **Solo registros activos**: los selects de configuración filtran con `state=0` (activo en la convención de Bsale)
- **Anti-duplicado**: `salesId = order_id` en cada documento previene emisiones dobles
- **HPOS compatible**: funciona con almacenamiento clásico y con el nuevo HPOS de WooCommerce 7.1+
- **Fail-open**: si la API no responde o el producto no tiene SKU, las ventas continúan sin interrupciones
- **Caché**: stock real cacheado 60 segundos por SKU+bodega
- **Log de eventos**: últimos 100 eventos visibles en la pestaña Webhook del panel

---

## Versión

`1.7.0` — Desarrollado para tiendas WooCommerce chilenas con facturación electrónica Bsale.
