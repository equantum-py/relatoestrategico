<?php
declare(strict_types=1);
// Soporte exclusivo de validación: estado privado, loopback y base con prefijo de pruebas.
$in = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';
$state = realpath((string) getenv('RELATO_TEST_STATE'));
$app = realpath((string) getenv('RELATO_APP_DIR'));
if (!$state || !$app || !is_file($state . '/only-pr5-tests') || !str_contains((string) ($in['db'] ?? ''), 'relato_pr5_test_')) {
    fwrite(STDERR, "Guardia de aislamiento rechazada.\n"); exit(2);
}
$db = (string) $in['db'];
if (!preg_match('/^relato_pr5_test_[a-f0-9]{8}$/', $db)) { exit(2); }
try {
    $port=(int)getenv('RELATO_TEST_DB_PORT');if($port<1024||$port>65535)exit(2);
    $root = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($mode === 'setup') {
        $user = 'pr5_' . substr($db, -8);
        $root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->exec('CREATE USER ' . $root->quote($user) . "@'localhost' IDENTIFIED BY " . $root->quote($in['db_pass']));
        $root->exec('GRANT ALL ON `' . $db . '`.* TO ' . $root->quote($user) . "@'localhost'");
        $cfg = ['db'=>['host'=>'127.0.0.1;port='.$port,'name'=>$db,'user'=>$user,'pass'=>$in['db_pass']], 'app'=>[
            'secret'=>$in['secret'], 'allowed_origin'=>'https://www.relatoestrategico.cl',
            'site_url'=>'https://www.relatoestrategico.cl','environment'=>'test','mail_transport'=>'file',
            'mail_test_dir'=>$state.'/mail','mail_from'=>'no-reply@pr5.test',
            'media_dir'=>$in['api_root'].'/media','media_private_dir'=>$state.'/private-media',
            'media_base_url'=>'https://api.relatoestrategico.cl/media',
            'session_idle_minutes'=>30,'session_max_hours'=>8,'ip_attempts_per_15min'=>1000,'account_attempts_per_hour'=>1000,
        ]];
        $configPath = $state . '/config.php';
        file_put_contents($configPath, '<?php return ' . var_export($cfg,true) . ';'); chmod($configPath,0600);
        putenv('RELATO_CONFIG='.$configPath);
        $root->exec('USE `' . $db . '`');
        $ddl = static function (string $file) use ($root): void {
            $text=preg_replace('/^\s*--.*$/m','',file_get_contents($file));
            foreach (explode(';',$text) as $sql) {
                $sql=trim($sql);if ($sql!=='' && !str_starts_with(strtoupper($sql),'SELECT')) $root->exec($sql);
            }
        };
        $ddl($app.'/migrations/001_schema.sql');
        $ddl($app.'/migrations/002_block_key/1_add_column_ddl.sql');
        require $app . '/src/bootstrap.php';
        Pages::ensureSeeded();
        $ddl($app.'/migrations/002_block_key/3_constraints_ddl.sql');
        foreach ($in['accounts'] as $role=>$account) {
            Db::pdo()->prepare('INSERT INTO admin_users (email,password_hash,role,active,created_at,updated_at) VALUES (?,?,?,1,?,?)')->execute([$account['email'],password_hash($account['password'],PASSWORD_DEFAULT),$role,Db::now(),Db::now()]);
        }
        foreach ($in['approved_posts'] as $post) {
            Db::pdo()->prepare('INSERT INTO posts (title,slug,category,excerpt,body,image_url,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?, ?,?)')->execute([$post['title'],$post['slug'],$post['category'],$post['excerpt'],$post['body'],$post['image_url'],'borrador',Db::now(),Db::now()]);
        }
        echo json_encode(['version'=>$root->query('SELECT VERSION()')->fetchColumn(),'blocks'=>(int)$root->query('SELECT COUNT(*) FROM page_blocks')->fetchColumn()]);
    } elseif ($mode === 'query' || $mode === 'sync') {
        putenv('RELATO_CONFIG='.$state.'/config.php');
        require $app . '/src/bootstrap.php';
        if ($mode === 'sync') { echo json_encode(Media::syncAll()); exit; }
        if (!preg_match('/^(SELECT\b|UPDATE admin_sessions SET expires_at\b)/i', trim((string)$in['sql']))) { exit(2); }
        $st=Db::pdo()->prepare($in['sql']);$st->execute($in['params']??[]);
        echo json_encode(str_starts_with(strtoupper(trim($in['sql'])),'SELECT')?$st->fetchAll():['affected'=>$st->rowCount()],JSON_UNESCAPED_UNICODE);
    } elseif ($mode === 'cleanup') {
        $user='pr5_'.substr($db,-8);
        $root->exec('DROP DATABASE `' . $db . '`');
        $root->exec('DROP USER IF EXISTS ' . $root->quote($user) . "@'localhost'");
        @unlink($state.'/config.php');echo '{"cleaned":true}';
    } else { exit(2); }
} catch (Throwable $e) {
    // Nunca imprimir cuerpos, credenciales o excepciones que incluyan datos de conexión.
    fwrite(STDERR, 'Fallo de fixture aislada: '.get_class($e)."\n");exit(1);
}
