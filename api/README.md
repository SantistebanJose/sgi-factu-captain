# API de registro de sucursales

## `POST /api/sucursal.php`

La API crea `sucursales/{ruc}/` con las carpetas `cdr/` y `xml/`, guarda el
certificado adjunto, un logo opcional y la configuración en `datos.json`.
El RUC debe tener 11 dígitos. Si ya existe una carpeta para ese RUC, responde
`409` sin sobrescribirla.

Use `multipart/form-data` para enviar archivos. Envíe el objeto de configuración
como texto JSON en el campo `datos`, el certificado en `certificado` y, si tiene,
la imagen en `logo`:

```json
{
  "ruc": "20123456789",
  "contrasenia_firma": "clave-del-certificado",
  "nombre_archivo_firma": "certificado.pfx",
  "ws": "beta",
  "usuario_sol": "USUARIOSECUNDARIO",
  "clave": "clave-sol",
  "series": {
    "boleta": "B001",
    "factura": "F001",
    "nota_credito": "FC01",
    "guia_remision": "T001"
  }
}
```

`ws` admite `beta` o `produccion`. El certificado debe ser `.pfx` o `.p12`.
El logo admite PNG, JPEG o WebP de hasta 5 MB. El certificado es obligatorio;
el logo es opcional. La API guarda la clave de firma y la clave SOL en el JSON,
por lo que el acceso HTTP a esa carpeta debe estar protegido.

Ejemplo con cURL:

```bash
curl -X POST http://localhost/sgi-factu-captain/api/sucursal.php \
  -F 'datos={"ruc":"20123456789","contrasenia_firma":"clave-del-certificado","nombre_archivo_firma":"certificado.pfx","ws":"beta","usuario_sol":"USUARIOSECUNDARIO","clave":"clave-sol","series":{"boleta":"B001","factura":"F001","nota_credito":"FC01","guia_remision":"T001"}}' \
  -F 'certificado=@/ruta/certificado.pfx' \
  -F 'logo=@/ruta/logo.png'
```

Las respuestas exitosas usan HTTP `201`; errores de validación usan `400` o
`422`, y un RUC ya registrado usa `409`. El certificado es requerido, así que
el registro debe enviarse como multipart.

La API registra los datos y archivos; los scripts actuales todavía usan valores
de emisor, series y rutas escritos directamente en el código. Para emitir con
cada sucursal, esos scripts deberán leer `datos.json` y usar las rutas de esa
sucursal.
