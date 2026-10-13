# Panel DBA - Gestor de Prácticas (v1.0)

Curso: Administración de Bases de Datos · Prof. Manuel Espinoza G.

## Estructura

```
proyecto/
├── login.php            # lee usuario + clave, valida y redirige a index.php
├── index.php            # menú principal (v1.0), requiere sesión
├── logout.php           # cierra la sesión
├── config/usuarios.php  # usuario => clave encriptada (bcrypt)
├── includes/auth.php    # funciones de sesión y autenticación
└── css/estilos.css
```

## Usuario inicial

- Usuario: `estudiante`
- Clave: `Cambiar123` (cámbiala)

## Generar la clave encriptada de un usuario nuevo

```bash
php -r 'echo password_hash("MiClave", PASSWORD_DEFAULT);'
```

Pega el resultado en `config/usuarios.php`.

## Despliegue (Virtualmin)

Copiar la carpeta `proyecto/` a `public_html/proyecto/` de tu sesión:
`https://aviles-lopez.fabahe.com/proyecto/login.php`

## Flujo de trabajo en GitHub

Repositorio privado compartido. Una rama por estudiante, cambios por Pull Request.
