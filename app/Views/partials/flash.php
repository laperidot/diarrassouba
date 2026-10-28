<?php
$flashes = App\Core\Session::takeFlash();
$icons = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'bell'];
foreach ($flashes as [$type, $msg]): ?>
<div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= icon($icons[$type] ?? 'bell') ?><span><?= e($msg) ?></span></div>
<?php endforeach;
$errs = App\Core\Session::errors();
unset($errs['duplicate'], $errs['similar']);
if (count($errs) > 1): ?>
<div class="flash flash-error" role="alert"><?= icon('alert') ?><div>Corrigez les champs suivants :<ul><?php foreach ($errs as $m): ?><li><?= e($m) ?></li><?php endforeach ?></ul></div></div>
<?php endif;
