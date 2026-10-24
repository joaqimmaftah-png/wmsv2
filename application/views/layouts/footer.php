  </main><!-- /.page -->
</div><!-- /#canvas -->
<?php
$find_role = isset($role_id) ? (int)$role_id : 5;
if (in_array($find_role, array(1, 2, 3, 4), TRUE)):
    $find_is_store = ($find_role === 3);
    $find_message = $find_is_store
        ? 'Find inventory by item code, part number or description.'
        : 'Find work orders, equipment and inventory. Exact identifiers work best.';
?>
<div class="global-find-root" id="global-find" hidden
     data-empty-message="<?= htmlspecialchars((string)$find_message, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="global-find-backdrop" data-find-close aria-hidden="true"></div>
  <section class="global-find-panel" role="dialog" aria-modal="true" aria-labelledby="global-find-title">
    <h2 class="sr-only" id="global-find-title">Find records</h2>
    <div class="global-find-head">
      <svg class="global-find-head-icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
      <label class="sr-only" for="global-find-input">Search operational records</label>
      <input type="search" id="global-find-input" class="global-find-input"
             placeholder="<?= $find_is_store ? 'Item code, part number or description…' : 'WO number, registration, item code or part number…'; ?>"
             autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search">
      <button type="button" class="global-find-close" data-find-close aria-label="Close global find">×</button>
    </div>
    <div class="sr-only" id="global-find-status" role="status" aria-live="polite" aria-atomic="true"></div>
    <div class="global-find-results" id="global-find-results" role="region" aria-label="Search results"></div>
    <div class="global-find-foot" aria-hidden="true">
      <span>↑↓ Navigate</span><span>Enter Open</span><span>Esc Close</span>
    </div>
  </section>
</div>
<?php endif; ?>
<script>
setTimeout(function(){
  var fl=document.querySelectorAll('.flash');
  for(var i=0;i<fl.length;i++){
    (function(f){
      f.style.transition='opacity .4s';
      f.style.opacity='0';
      setTimeout(function(){if(f.parentNode)f.parentNode.removeChild(f);},450);
    })(fl[i]);
  }
},5000);
</script>
</body>
</html>
