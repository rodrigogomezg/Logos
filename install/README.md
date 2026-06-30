# install/

`schema_limpio.sql` es el esquema completo de BRON (todas las tablas,
sin ninguna fila de datos) para instalar el sistema de cero en un
cliente nuevo. Es lo que ejecuta el asistente de instalación
(`pos/instalar.html`) cuando esta PC se configura como servidor de la
base de datos.

Se generó con:

```
mysqldump --no-data --skip-comments --skip-add-drop-table -h127.0.0.1 -P3307 -uroot bron > install/schema_limpio.sql
```

y luego se le quitaron los valores `AUTO_INCREMENT=N` de cada tabla
(para que una instalación nueva empiece los IDs en 1).

**Importante:** cada vez que se agregue una migración nueva en
`migrate/*.sql`, hay que regenerar este archivo con el mismo comando
(contra la base ya migrada) y volver a quitar los `AUTO_INCREMENT=N`
con:

```
sed -i -E 's/ AUTO_INCREMENT=[0-9]+//' install/schema_limpio.sql
```

Si no se regenera, las instalaciones nuevas van a quedar con un
esquema desactualizado respecto a `migrate/`.
