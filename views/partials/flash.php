<?php
$map = ['success' => 'success', 'error' => 'danger', 'info' => 'info'];
foreach ($map as $key => $variant):
    $msg = flash($key);
    if ($msg === null) {
        continue;
    }
    ?>
    <div class="alert alert-<?= $variant ?> alert-dismissible fade show" role="alert">
        <?= e($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
<?php endforeach; ?>
