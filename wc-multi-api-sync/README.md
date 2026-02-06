# WC Multi API Sync

Plugin de WordPress/WooCommerce para sincronizar productos desde múltiples proveedores externos.

## Requisitos
- WordPress >= 5.8
- WooCommerce >= 3.9
- PHP >= 7.4

## Instalación
1. Copia la carpeta `wc-multi-api-sync` dentro de `wp-content/plugins/`.
2. Activa el plugin desde el panel de plugins.
3. Ve a **WooCommerce → WC Multi API Sync**.

## Configurar un proveedor
1. En **Providers**, completa:
   - Nombre, Base URL, Endpoints de productos y notificación.
   - Tipo de autenticación (none, api_key_header, bearer, basic).
   - Headers adicionales y parámetros por defecto.
   - Frecuencia de sincronización.
2. Guarda el proveedor.

Ejemplo de proveedor (`provider-seed.json`):
```json
{
  "name": "Proveedor Demo",
  "base_url": "https://api.tienda-demo.com",
  "products_endpoint": "/v1/products",
  "notify_endpoint": "https://api.tienda-demo.com/v1/order-notify",
  "auth_type": "bearer",
  "auth_config": {"api_key": "YOUR_API_KEY"},
  "headers": {"X-Channel": "WooCommerce"},
  "default_params": {"per_page": 50},
  "sync_frequency": "hourly",
  "active": true
}
```

## Mapeo de productos
- En **Mappings**, selecciona un proveedor.
- Usa JSON con dot notation. Ejemplos: `images.0.url`, `attributes.color[0]`.
- Puedes usar transformaciones: `trim`, `int`, `float`, `default`, `multiply`, `prefix`, `suffix`, `currency_convert`.
- Botón **Test Endpoint** para obtener JSON de ejemplo.
- Botón **Test Mapping** para previsualizar sin crear productos.

Ejemplo de mapeo:
```json
{
  "title": {"path": "title", "transform": {"trim": true}},
  "description": {"path": "description"},
  "sku": {"path": "sku"},
  "regular_price": {"path": "price", "transform": {"float": true}},
  "stock": {"path": "stock", "transform": {"int": true}},
  "images": {"path": "images"},
  "categories": {"path": "categories"},
  "attributes": {"path": "attributes"},
  "variations": {"path": "variations"}
}
```

## Importación manual
En la tabla de mapeos, haz clic en **Import Now** para ejecutar un job de sincronización.

También puedes forzar una reimportación manual con Action Scheduler:
```bash
wp action-scheduler run --hook=wc_mas_sync_provider
```

## Tabla de index para externos (recomendada)
Para evitar búsquedas lentas en `post_meta`, el plugin crea la tabla `wp_wcmas_external_map` en la activación. Esta tabla indexa la relación `provider_id + external_id` → `product_id`.

Si la tabla no existe (por ejemplo, si no se ejecutó la activación), el plugin hace fallback a una búsqueda por `meta_query`.

SQL recomendado:
```sql
CREATE TABLE IF NOT EXISTS `wp_wcmas_external_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` bigint(20) unsigned NOT NULL,
  `external_id` varchar(191) NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_external_unique` (`provider_id`,`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Migración opcional (productos existentes)
Si tenías productos creados con el patrón de SKU `ext-{provider_id}-{external_id}`, puedes poblar la tabla:
```bash
wp eval 'require_once WP_PLUGIN_DIR . "/wc-multi-api-sync/admin/migrations.php"; wc_mas_run_external_map_migration();'
```

## Logs
En **Logs** puedes filtrar por proveedor, nivel y fecha.

Ejemplos reales de logs:
```json
{"processed":20,"created":3,"updated":12,"skipped":4,"errors":1}
```

```json
{"message":"Product updated","product_id":123,"external_id":"A-100","changes":{"regular_price":{"from":"10.00","to":"12.00"}}}
```

```json
{"message":"Image sideload failed","product_id":123,"url":"https://cdn.tienda.com/bad.jpg","error":"Invalid URL"}
```

## Notificación de ventas
Cuando un pedido se completa, el plugin enviará un POST al endpoint configurado con el siguiente payload:
```json
{
  "order_id": 9876,
  "order_key": "wc_order_abc123",
  "sku": "ABC-123",
  "quantity": 2,
  "price": "19.90",
  "currency": "HNL",
  "customer": {
    "name": "Juan Perez",
    "email": "juan@example.com"
  },
  "timestamp": "2026-02-06T12:00:00Z"
}
```

## Hooks disponibles
- `wc_mas_pre_map_product` (antes de mapear).
- `wc_mas_map_value` (transformación de valores).
- `wc_mas_post_product_save` (después de guardar producto).
- `wc_mas_before_notify_provider` (antes de notificar venta).

## Tests
Los tests usan la suite oficial de WP y WooCommerce.
1. Configura la suite de pruebas de WordPress y WooCommerce.
2. Ejecuta:
   ```bash
   phpunit --configuration tests/phpunit.xml.dist
   ```

## Ejemplo de respuesta de productos
```json
{
  "page": 1,
  "per_page": 50,
  "total": 345,
  "products": [
    {
      "id": "A-123",
      "sku": "ABC-123",
      "title": "Camiseta Azul",
      "description": "Camiseta de algodón...",
      "price": "19.90",
      "currency": "HNL",
      "stock": 23,
      "images": [
        "https://cdn.tienda.com/images/abc-123-1.jpg",
        "https://cdn.tienda.com/images/abc-123-2.jpg"
      ],
      "categories": ["Ropa", "Hombre"],
      "attributes": {
        "talla": ["S", "M", "L"],
        "color": ["azul"]
      },
      "variations": [
        {
          "sku": "ABC-123-S-AZ",
          "attributes": {"talla": "S", "color": "azul"},
          "price": "19.90",
          "stock": 5
        }
      ]
    }
  ]
}
```

## Seguridad
- Usa nonces y capacidades en el admin.
- Autenticación cifrada usando `openssl_encrypt` y `wp_salt()`.
