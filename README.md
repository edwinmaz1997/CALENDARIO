# CALENDARIO · Cotizador multiempresa

Sistema de cotizaciones multiempresa con calendario de eventos. PHP 7.4+ y MySQL/MariaDB, responsive para cualquier dispositivo.

## Funciones

- **Empresas** con nombre, NIT, direccion, contacto, logo, colores, moneda, prefijo de numeracion y texto al pie.
- **Un usuario por empresa**, mas el administrador general que gestiona empresas y usuarios y puede elegir con que empresa trabajar.
- **Catalogo de productos y de clientes** independiente para cada empresa.
- **Cotizaciones** con numeracion correlativa por empresa (ej. `EVT-00001`) y solo monto total, sin descuentos.
  - Se crean como **pendientes**.
  - Al marcarlas **aceptadas** aparecen en el **calendario** en la fecha del evento.
  - Tambien pueden pasar a **rechazadas**.
- **PDF** de la cotizacion con el logo y los colores de la empresa.
- **Imagen opcional** por cotizacion (referencia del montaje, decoracion, etc.) que se muestra en la vista y en el PDF.
- **Archivos adjuntos** en cada cotizacion con titulo (Anticipo, Contrato, etc.): imagenes, PDF, Word o Excel. Se guardan protegidos y solo se ven con sesion.
- **Control de pagos** por cotizacion: anticipos, abonos y pago final con descripcion, monto, metodo y comprobante; muestra lo pagado y el saldo pendiente (tambien en el PDF, la lista y el inicio).
- **Recordatorio por correo** al correo de la empresa un dia antes de cada evento aceptado, con el PDF adjunto.
- **Proveedores** con % de comision: el precio de venta ya incluye la comision (ej. 5000 con 10% => se paga 4500 al proveedor). Las cotizaciones aceptadas generan el adeudo; se registran abonos por proveedor y opcionalmente por evento.
- **Cotizaciones enviadas**: boton para marcar como enviada al cliente; filtros "Por enviar" y "Enviadas".
- **Reportes** mensual, anual o por rango: eventos, vendido, cobrado, por cobrar, costo de proveedores, ganancia, flujo de dinero, adeudos por proveedor, conversion de cotizaciones; exportable a Excel (CSV) e imprimible.
- **Campanita de alertas**: eventos aceptados a 5 dias o menos (o ya pasados) que aun tienen saldo con el cliente o con proveedores, con **reporte de liquidacion** por evento (cobro al cliente, pago a cada proveedor, ganancia y dinero en mano), imprimible.
- **Dashboard** con indicadores (pendientes, por enviar, por cobrar, deuda a proveedores), calendario de eventos, proximos eventos y pendientes.

## Instalacion en cPanel

1. Crea la base de datos y su usuario en cPanel (MySQL Databases) y asigna todos los privilegios.
2. En phpMyAdmin importa `sql/schema.sql` sobre esa base.
3. Sube el contenido del repositorio a la carpeta del dominio o subdominio.
4. Copia `config/config.example.php` como `config/config.php` y llena los datos de conexion.
5. Verifica que la carpeta `uploads/logos` tenga permisos de escritura (755).
6. Ingresa con usuario `admin` y contrasena `admin123`. **Cambia la contrasena en "Mi perfil"** al primer ingreso.
7. Crea las empresas y su usuario desde **Administracion**. El campo **Correo** de la empresa es donde llegan los recordatorios.

### Correo y recordatorios

1. En cPanel crea una cuenta de correo (ej. `notificaciones@tudominio.com`).
2. En `config/config.php` llena la seccion `mail` (remitente y datos SMTP) y `app.url`.
3. En cPanel > **Cron Jobs** agrega (cada hora de 7:00 a 20:00):
   ```
   0 7-20 * * * /usr/local/bin/php /home/USUARIO/RUTA_DEL_SISTEMA/cron/recordatorios.php >/dev/null 2>&1
   ```
   Cada evento se notifica una sola vez. Correrlo varias veces al dia cubre tambien las cotizaciones aceptadas el mismo dia anterior al evento.
4. Para probar sin esperar al cron, abre una cotizacion aceptada y usa **Enviar ahora** en "Recordatorio por correo".

Si el hosting no permite ejecutar PHP desde el cron, se puede usar la URL con la clave `cron_token`:
```
wget -q -O /dev/null "https://tudominio.com/cron_recordatorios.php?token=TU_CRON_TOKEN"
```

### Actualizar una instalacion existente

Ejecuta en phpMyAdmin los archivos de `sql/migraciones/` que aun no hayas aplicado, en orden.

No se necesita Composer ni terminal: FPDF, PHPMailer, FullCalendar, Lucide y el CSS compilado ya vienen incluidos.

## Estructura

| Ruta | Contenido |
|---|---|
| `*.php` (raiz) | Paginas del sistema |
| `includes/` | Arranque, conexion, autenticacion, layout y generacion de PDF |
| `config/` | Configuracion (`config.php` no se versiona) |
| `sql/schema.sql` | Estructura de la base de datos |
| `lib/` | FPDF 1.9 y PHPMailer 6.10 |
| `cron/` | Tarea programada de recordatorios |
| `sql/migraciones/` | Cambios para bases ya instaladas |
| `assets/` | CSS, JS y librerias de terceros |
| `uploads/` | Logos y adjuntos subidos (no se versionan) |

## Ramas y despliegue

- `dev`: desarrollo. Cada push a `dev` se publica solo en el hosting con GitHub Actions por FTP (`.github/workflows/deploy.yml`).
- `produccion`: version estable.

Secretos requeridos en GitHub (Settings > Secrets and variables > Actions):

| Secreto | Ejemplo |
|---|---|
| `FTP_SERVER` | `ftp.estudiocero.com.gt` |
| `FTP_USERNAME` | usuario FTP |
| `FTP_PASSWORD` | contrasena FTP |
| `FTP_SERVER_DIR` | `./` si la cuenta FTP apunta a la carpeta del sistema, o `calendario.nuevaexpress.com/` si apunta a la raiz de la cuenta |

El despliegue no sube `config/config.php` ni los logos y adjuntos.

## Desarrollo de estilos

El CSS de Tailwind ya va compilado en `assets/css/tailwind.css`. Si se agregan clases nuevas hay que regenerarlo:

```bash
npm install
npm run build:css
```
