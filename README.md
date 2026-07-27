# Coordinación de ayuda

Aplicación web con registro centralizado en servidor.

## Requisitos

- PHP 8.1 o superior.
- Extensión `pdo_sqlite` habilitada.
- La carpeta `storage/` debe tener permiso de escritura para PHP.
- Servir por HTTPS en producción.

## Base de datos

La aplicación crea automáticamente `storage/coord_ayuda.sqlite` en el primer acceso. El archivo no se versiona en Git.

Se almacenan de forma centralizada:

- Inscripciones y datos de contacto.
- Necesidades y objetivos.
- Tareas, plazas, zonas y estados.

La administración usa sesión PHP `HttpOnly`; la contraseña no está en JavaScript. El CSV global se descarga desde `Necesidades > Exportar datos` después de iniciar sesión.

## Comprobación rápida

Abrrir `api.php?action=health`. Debe devolver `{"ok":true,"database":"sqlite",...}`.

## Cambiar contraseña de administración

Definir la variable de entorno `AYUDA_ADMIN_PASSWORD_HASH` con un hash generado así:

```bash
php -r 'echo password_hash("TU_NUEVA_CONTRASEÑA", PASSWORD_DEFAULT), PHP_EOL;'
```

## Importante sobre GitHub Pages

GitHub Pages solo sirve archivos estáticos y no ejecuta PHP. Este repositorio debe desplegarse en un hosting con PHP o en un servicio equivalente que ejecute el backend.
