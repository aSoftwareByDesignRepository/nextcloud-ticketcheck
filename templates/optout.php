<?php
/**
 * External-mail opt-out landing (NH-02). States: confirm | done | invalid.
 * Self-contained inside layout.guest.php — no PII is ever rendered.
 * @var string $_['state']
 * @var int $_['t']
 * @var string $_['e']
 * @var string $_['k']
 */
$l = $_['l'];
$state = $_['state'] ?? 'invalid';
?>
<main class="helpdesk-portal" style="display:flex;justify-content:center;padding:48px 16px;">
  <section class="helpdesk-card" style="max-width:420px;width:100%;text-align:center;" role="region" aria-labelledby="extmail-optout-title">
    <div class="helpdesk-card__body" style="padding:32px 24px;">
      <h1 id="extmail-optout-title" class="helpdesk-card__title" style="margin:0 0 12px;"><?php p($l->t('extmail_optout_title')); ?></h1>
      <?php if ($state === 'confirm'): ?>
        <p><?php p($l->t('extmail_optout_confirm')); ?></p>
        <form method="post" action="<?php p($_['formUrl']); ?>">
          <input type="hidden" name="t" value="<?php p((int)$_['t']); ?>">
          <input type="hidden" name="e" value="<?php p($_['e']); ?>">
          <input type="hidden" name="k" value="<?php p($_['k']); ?>">
          <button type="submit" class="helpdesk-btn helpdesk-btn--primary helpdesk-btn--lg" style="min-height:48px;">
            <?php p($l->t('extmail_optout_button')); ?>
          </button>
        </form>
      <?php elseif ($state === 'done'): ?>
        <p role="status"><?php p($l->t('extmail_optout_done')); ?></p>
      <?php else: ?>
        <p role="alert"><?php p($l->t('extmail_optout_invalid')); ?></p>
      <?php endif; ?>
    </div>
  </section>
</main>
