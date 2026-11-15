<?php
// El contenido que pinta el servidor (#page-content, datos de app-page.php): en una parada va arriba, donde luego
// sale su tarjeta (que lo sustituye); en una línea o en la red, debajo. El horario de hoy lleva su fecha (data-date).
?>
                <section id="page-content" class="page-content glass" data-page-type="<?= $aContent['type'] ?>" data-page-id="<?= htmlspecialchars($aContent['id']) ?>"<?php if (empty($aContent['sections'])): ?> hidden<?php endif; ?>>
<?php if ($aContent['heading'] !== null): ?>
                    <h1><?= htmlspecialchars($aContent['heading']) ?></h1>
<?php endif; ?>
<?php foreach ($aContent['sections'] as $aSection):
    $sListTag = 'ul';
    if (!empty($aSection['ordered'])) {
        $sListTag = 'ol';
    }
?>
                    <div class="page-content-section"<?php if (isset($aSection['date'])): ?> data-date="<?= $aSection['date'] ?>"<?php endif; ?>>
                        <h2><?= htmlspecialchars($aSection['title']) ?></h2>
<?php if (!empty($aSection['items'])): ?>
                        <<?= $sListTag ?> class="page-content-list">
<?php foreach ($aSection['items'] as $aItem): ?>
                            <li><a href="<?= htmlspecialchars($aItem['href']) ?>"><?= htmlspecialchars($aItem['label']) ?></a><?php if (isset($aItem['times'])): ?> <span class="page-content-times"><?= htmlspecialchars($aItem['times']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
                        </<?= $sListTag ?>>
<?php endif; ?>
<?php if (isset($aSection['empty'])): ?>
                        <p><?= htmlspecialchars($aSection['empty']) ?></p>
<?php endif; ?>
                    </div>
<?php endforeach; ?>
                    <noscript><p><a href="/">Bide+</a> · <?= htmlspecialchars(\Core\Lang::t('seo.enableJs', $sLang)) ?></p></noscript>
                </section>
