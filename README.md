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
- **Dashboard** con indicadores, calendario de eventos, proximos eventos y pendientes.

## Instalacion en cPanel

1. Crea la base de datos y su usuario en cPanel (MySQL Databases) y asigna todos los privilegios.
2. En phpMyAdmin importa `sql/schema.sql` sobre esa base.
3. Sube el contenido del repositorio a la carpeta del dominio o subdominio.
4. Copia `config/config.example.php` como `config/config.php` y llena los datos de conexion.
5. Verifica que la carpeta `uploads/logos` tenga permisos de escritura (755).
6. Ingresa con usuario `admin` y contrasena `admin123`. **Cambia la contrasena en "Mi perfil"** al primer ingreso.
7. Crea las empresas y su usuario desde **Administracion**.

No se necesita Composer ni terminal: FPDF, FullCalendar, Lucide y el CSS compilado ya vienen incluidos.

## Estructura

| Ruta | Contenido |
|---|---|
| `*.php` (raiz) | Paginas del sistema |
| `includes/` | Arranque, conexion, autenticacion, layout y generacion de PDF |
| `config/` | Configuracion (`config.php` no se versiona) |
| `sql/schema.sql` | Estructura de la base de datos |
| `lib/fpdf/` | Libreria FPDF 1.9 |
| `assets/` | CSS, JS y librerias de terceros |
| `uploads/logos/` | Logos subidos (no se versionan) |

## Ramas

- `dev`: desarrollo.
- `produccion`: lo que esta publicado en el hosting.

## Desarrollo de estilos

El CSS de Tailwind ya va compilado en `assets/css/tailwind.css`. Si se agregan clases nuevas hay que regenerarlo:

```bash
npm install
npm run build:css
```
