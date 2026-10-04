<?php
// Copia la infografía SVG a /images y crea (si no existe) el módulo
// mod_custom que la muestra en el panel de inicio de la Administración.
// Sale con código distinto de 0 si la base de datos aún no está lista.

$prefix = getenv('JOOMLA_DB_PREFIX') ?: 'jos_';
$title  = 'Parcial 2 — Arquitectura del proyecto';
$svg    = 'parcial2_arquitectura.svg';

try {
    $db = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s',
            getenv('JOOMLA_DB_HOST'), getenv('JOOMLA_DB_PORT') ?: '5432', getenv('JOOMLA_DB_NAME')),
        getenv('JOOMLA_DB_USER'), getenv('JOOMLA_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // La imagen se copia siempre para reflejar cambios en el SVG
    $dest = "/var/www/html/images/$svg";
    copy("/parcial2/$svg", $dest);
    chown($dest, 'www-data');
    chgrp($dest, 'www-data');

    $q = $db->prepare("SELECT COUNT(*) FROM {$prefix}modules WHERE title = ? AND client_id = 1");
    $q->execute([$title]);
    if ($q->fetchColumn() > 0) {
        exit(0);
    }

    $content = '<a href="/images/' . $svg . '" target="_blank" rel="noopener">'
        . '<img src="/images/' . $svg . '" alt="Arquitectura del Parcial 2: nginx, Joomla, PostgreSQL, Jupyter y Grafana en Docker Compose"'
        . ' style="width:100%;height:auto;border-radius:12px;display:block"></a>';
    $params = '{"prepare_content":"0","layout":"_:default","moduleclass_sfx":"","cache":"0","module_tag":"div",'
        . '"bootstrap_size":"12","header_tag":"h2","header_class":"","style":"0"}';

    $db->beginTransaction();
    $ins = $db->prepare(
        "INSERT INTO {$prefix}modules
            (asset_id, title, note, content, ordering, position, published, module,
             access, showtitle, params, client_id, language)
         VALUES (0, ?, '', ?, 0, 'cpanel', 1, 'mod_custom', 1, 1, ?, 1, '*')
         RETURNING id"
    );
    $ins->execute([$title, $content, $params]);
    $id = $ins->fetchColumn();
    $db->prepare("INSERT INTO {$prefix}modules_menu (moduleid, menuid) VALUES (?, 0)")->execute([$id]);
    $db->commit();

    echo "[parcial2] Infografía publicada en el panel de administración (módulo $id)\n";
    exit(0);
} catch (Throwable $e) {
    exit(1);
}
