<?php
/**
 * Inspection Forms Dashboard / Index
 * Displays all inspection form templates organized by equipment category,
 * with bilingual support (EN/FR), search filtering, submission counts,
 * and quick access to blank forms and record histories.
 *
 * Vars: $templates, $record_counts, $lang ('en'|'fr'), $page_title
 */
$lang = isset($lang) && $lang === 'fr' ? 'fr' : 'en';
$L = function($en, $fr) use ($lang) { return $lang === 'fr' ? $fr : $en; };

$counts = isset($record_counts) ? $record_counts : array();
$total_submissions = array_sum($counts);

// Group templates by category
$categories = array();
foreach ($templates as $t) {
    $cat = ($lang === 'fr') ? (isset($t->category_fr) ? $t->category_fr : 'Général') : (isset($t->category_en) ? $t->category_en : 'General');
    if (!isset($categories[$cat])) {
        $categories[$cat] = array();
    }
    $categories[$cat][] = $t;
}
?>

<style>
.insp-dash {
  max-width: 1200px;
  margin: 0 auto;
  padding: 24px 24px 60px;
}
.insp-dash-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 22px;
}
.insp-dash-title {
  font-size: 22px;
  font-weight: 700;
  color: var(--t1);
  letter-spacing: -0.3px;
  margin-bottom: 4px;
}
.insp-dash-sub {
  font-size: 13px;
  color: var(--t2);
}

/* Lang switcher */
.insp-lang-toggle {
  display: inline-flex;
  background: var(--surface);
  border: 1px solid var(--sep);
  border-radius: 10px;
  padding: 3px;
  gap: 3px;
}
.insp-lang-btn {
  padding: 6px 14px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 7px;
  text-decoration: none;
  color: var(--t2);
  transition: all .15s;
}
.insp-lang-btn.active {
  background: var(--blue-fill);
  color: var(--blue);
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.insp-lang-btn:hover:not(.active) {
  color: var(--t1);
  background: var(--g2);
}

/* Stats summary */
.insp-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 24px;
}
.insp-stat-card {
  background: var(--surface);
  border: 1px solid var(--sep);
  border-radius: 12px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.insp-stat-num {
  font-size: 22px;
  font-weight: 700;
  color: var(--t1);
}
.insp-stat-lbl {
  font-size: 11.5px;
  color: var(--t3);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
}
.insp-stat-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: var(--blue-fill);
  color: var(--blue);
  display: flex;
  align-items: center;
  justify-content: center;
}
.insp-stat-icon svg {
  width: 18px;
  height: 18px;
  stroke: currentColor;
  fill: none;
  stroke-width: 1.8;
}

/* Filter bar & Search */
.insp-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 26px;
}
.insp-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.insp-pill {
  padding: 6px 14px;
  font-size: 12px;
  font-weight: 500;
  border-radius: 20px;
  border: 1px solid var(--sep);
  background: var(--surface);
  color: var(--t2);
  cursor: pointer;
  text-decoration: none;
  transition: all .15s;
}
.insp-pill:hover {
  background: var(--g2);
  color: var(--t1);
}
.insp-pill.active {
  background: var(--blue-fill);
  color: var(--blue);
  border-color: var(--blue);
  font-weight: 600;
}
.insp-search-box {
  position: relative;
  min-width: 260px;
}
.insp-search-box input {
  width: 100%;
  padding: 7px 12px 7px 32px;
  font-size: 12.5px;
  border-radius: 20px;
  border: 1px solid var(--sep);
  background: var(--surface);
  color: var(--t1);
  outline: none;
}
.insp-search-box input:focus {
  border-color: var(--blue);
}
.insp-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  color: var(--t3);
  pointer-events: none;
}

/* Category Sections & Cards */
.insp-section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 28px 0 14px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--sep);
}
.insp-section-title {
  font-size: 15px;
  font-weight: 700;
  color: var(--t1);
  letter-spacing: -0.2px;
}
.insp-section-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 12px;
  background: var(--g2);
  color: var(--t3);
}

.insp-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 16px;
}
.insp-card {
  background: var(--surface);
  border: 1px solid var(--sep);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  transition: transform .15s, box-shadow .15s, border-color .15s;
}
.insp-card:hover {
  border-color: var(--blue);
  transform: translateY(-2px);
  box-shadow: 0 4px 14px rgba(0,0,0,0.05);
}
.insp-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
}
.insp-card-name {
  font-size: 15px;
  font-weight: 600;
  color: var(--t1);
  line-height: 1.35;
  margin-bottom: 3px;
}
.insp-card-sub {
  font-size: 12px;
  color: var(--t3);
  margin-bottom: 16px;
  font-style: italic;
}
.insp-card-tag {
  display: inline-block;
  font-size: 10.5px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 6px;
  background: var(--g2);
  color: var(--t3);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.insp-card-count {
  display: inline-block;
  font-size: 11px;
  color: var(--t3);
  background: var(--surface);
  border: 1px solid var(--sep);
  padding: 2px 8px;
  border-radius: 10px;
}
.insp-card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--sep);
}

.insp-btn-primary {
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  color: #fff;
  background: var(--blue);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: opacity .15s;
}
.insp-btn-primary:hover {
  opacity: 0.9;
  color: #fff;
}
.insp-btn-ghost {
  padding: 8px 14px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  text-decoration: none;
  background: var(--g2);
  color: var(--t2);
  border: 1px solid var(--sep);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all .15s;
}
.insp-btn-ghost:hover {
  background: var(--surface);
  color: var(--t1);
  border-color: var(--sep-strong);
}
.insp-no-results {
  padding: 40px;
  text-align: center;
  color: var(--t3);
  font-size: 14px;
  background: var(--surface);
  border: 1px solid var(--sep);
  border-radius: 12px;
  margin-top: 20px;
  display: none;
}
</style>

<div class="insp-dash">

  <!-- Header Area -->
  <div class="insp-dash-header">
    <div>
      <div class="insp-dash-title">
        <?= $L('Inspection Forms', 'Formulaires d’inspection'); ?>
      </div>
      <div class="insp-dash-sub">
        <?= $L('Choose a checklist to run, or view previously submitted inspections.',
               'Choisissez une liste de contrôle à exécuter ou consultez les inspections déjà soumises.'); ?>
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <?php if (!empty($can_manage)): ?>
      <a href="<?= base_url('inspections/templates'); ?>"
         style="padding:8px 14px;border-radius:8px;font-size:12px;text-decoration:none;color:var(--t2);background:var(--surface);border:1px solid var(--sep);">
        ⚙ <?= $L('Manage Templates', 'Gérer les modèles'); ?>
      </a>
      <?php endif; ?>

      <!-- Language Selector -->
      <div class="insp-lang-toggle">
        <a href="<?= base_url('inspections?lang=en'); ?>" class="insp-lang-btn <?= $lang === 'en' ? 'active' : ''; ?>">
          🇬🇧 English
        </a>
        <a href="<?= base_url('inspections?lang=fr'); ?>" class="insp-lang-btn <?= $lang === 'fr' ? 'active' : ''; ?>">
          🇫🇷 Français
        </a>
      </div>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if ($this->session->flashdata('success')): ?>
    <div class="flash-msg flash-ok" style="padding:10px 14px;border-radius:8px;background:var(--blue-fill);color:var(--blue);margin-bottom:16px;font-size:13px;">
      <?= $this->session->flashdata('success'); ?>
    </div>
  <?php endif; ?>
  <?php if ($this->session->flashdata('error')): ?>
    <div class="flash-msg flash-err" style="padding:10px 14px;border-radius:8px;background:var(--red-fill);color:var(--red);margin-bottom:16px;font-size:13px;">
      <?= $this->session->flashdata('error'); ?>
    </div>
  <?php endif; ?>

  <!-- Stats Strip -->
  <div class="insp-stats-grid">
    <div class="insp-stat-card">
      <div>
        <div class="insp-stat-lbl"><?= $L('Active Forms', 'Formulaires actifs'); ?></div>
        <div class="insp-stat-num"><?= count($templates); ?></div>
      </div>
      <div class="insp-stat-icon">
        <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
      </div>
    </div>
    <div class="insp-stat-card">
      <div>
        <div class="insp-stat-lbl"><?= $L('Categories', 'Catégories'); ?></div>
        <div class="insp-stat-num"><?= count($categories); ?></div>
      </div>
      <div class="insp-stat-icon" style="background:#e8f4fd;color:#0284c7;">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      </div>
    </div>
    <div class="insp-stat-card">
      <div>
        <div class="insp-stat-lbl"><?= $L('Total Submitted', 'Total soumis'); ?></div>
        <div class="insp-stat-num"><?= (int)$total_submissions; ?></div>
      </div>
      <div class="insp-stat-icon" style="background:#eefbe8;color:#16a34a;">
        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
    </div>
    <div class="insp-stat-card">
      <div>
        <div class="insp-stat-lbl"><?= $L('Languages', 'Langues'); ?></div>
        <div class="insp-stat-num" style="font-size:16px;letter-spacing:1px;">EN &bull; FR</div>
      </div>
      <div class="insp-stat-icon" style="background:#fef3c7;color:#d97706;">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
      </div>
    </div>
  </div>

  <!-- Toolbar: Filter Pills & Search -->
  <div class="insp-toolbar">
    <div class="insp-pills" id="category-pills">
      <button type="button" class="insp-pill active" data-filter="all">
        <?= $L('All Forms', 'Tous les formulaires'); ?> (<?= count($templates); ?>)
      </button>
      <?php foreach ($categories as $cat_name => $cat_templates): ?>
        <button type="button" class="insp-pill" data-filter="<?= htmlspecialchars(strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $cat_name))); ?>">
          <?= htmlspecialchars($cat_name); ?> (<?= count($cat_templates); ?>)
        </button>
      <?php endforeach; ?>
    </div>

    <div class="insp-search-box">
      <svg class="insp-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="insp-search" placeholder="<?= $L('Filter forms…', 'Filtrer les formulaires…'); ?>">
    </div>
  </div>

  <!-- Forms Organized by Category -->
  <div id="forms-container">
    <?php foreach ($categories as $cat_name => $cat_templates):
      $cat_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $cat_name));
    ?>
      <div class="insp-section-block" data-category="<?= htmlspecialchars($cat_slug); ?>">
        <div class="insp-section-header">
          <div class="insp-section-title"><?= htmlspecialchars($cat_name); ?></div>
          <span class="insp-section-badge"><?= count($cat_templates); ?></span>
        </div>

        <div class="insp-grid">
          <?php foreach ($cat_templates as $t):
            $rec_count = isset($counts[$t->id]) ? (int)$counts[$t->id] : 0;
            $main_title = ($lang === 'fr') ? $t->name_fr : $t->name_en;
            $sub_title  = ($lang === 'fr') ? $t->name_en : $t->name_fr;
          ?>
            <div class="insp-card"
                 data-name="<?= htmlspecialchars(strtolower($t->name_en . ' ' . $t->name_fr . ' ' . $t->code)); ?>"
                 data-category="<?= htmlspecialchars($cat_slug); ?>">
              <div>
                <div class="insp-card-top">
                  <span class="insp-card-tag"><?= strtoupper(htmlspecialchars($t->code)); ?></span>
                  <span class="insp-card-count">
                    <?= $rec_count; ?> <?= $L($rec_count === 1 ? 'record' : 'records', $rec_count === 1 ? 'enregistrement' : 'enregistrements'); ?>
                  </span>
                </div>
                <div class="insp-card-name"><?= htmlspecialchars($main_title); ?></div>
                <div class="insp-card-sub"><?= htmlspecialchars($sub_title); ?></div>
              </div>

              <div class="insp-card-actions">
                <a href="<?= base_url('inspections/fill/' . $t->code . '/en'); ?>"
                   class="insp-btn-primary"
                   title="<?= $L('Fill form in English', 'Remplir le formulaire en anglais'); ?>">
                  + <?= $L('New (English)', 'Nouveau (en anglais)'); ?>
                </a>
                <a href="<?= base_url('inspections/fill/' . $t->code . '/fr'); ?>"
                   class="insp-btn-primary"
                   title="<?= $L('Fill form in French', 'Remplir le formulaire en français'); ?>">
                  + <?= $L('New (French)', 'Nouveau (en français)'); ?>
                </a>
                <a href="<?= base_url('inspections/records/' . $t->code . '?lang=' . $lang); ?>"
                   class="insp-btn-ghost"
                   title="<?= $L('View submitted inspection records', 'Consulter les enregistrements soumis'); ?>">
                  <?= $L('Records', 'Enregistrements'); ?>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="insp-no-results" id="insp-no-results">
      <?= $L('No inspection forms match your search.', 'Aucun formulaire d’inspection ne correspond à votre recherche.'); ?>
    </div>
  </div>

</div>

<script>
(function(){
  var searchInput = document.getElementById('insp-search');
  var pills = document.querySelectorAll('#category-pills .insp-pill');
  var cards = document.querySelectorAll('.insp-card');
  var sections = document.querySelectorAll('.insp-section-block');
  var noResults = document.getElementById('insp-no-results');

  var currentFilter = 'all';

  function applyFilters() {
    var query = (searchInput.value || '').trim().toLowerCase();
    var visibleCardsCount = 0;

    sections.forEach(function(sec){
      var secCat = sec.getAttribute('data-category');
      var catMatches = (currentFilter === 'all' || currentFilter === secCat);
      var secVisibleCards = 0;

      var secCards = sec.querySelectorAll('.insp-card');
      secCards.forEach(function(card){
        var nameText = card.getAttribute('data-name') || '';
        var textMatches = (!query || nameText.indexOf(query) !== -1);

        if (catMatches && textMatches) {
          card.style.display = 'flex';
          secVisibleCards++;
          visibleCardsCount++;
        } else {
          card.style.display = 'none';
        }
      });

      if (secVisibleCards > 0) {
        sec.style.display = 'block';
      } else {
        sec.style.display = 'none';
      }
    });

    if (noResults) {
      noResults.style.display = (visibleCardsCount === 0) ? 'block' : 'none';
    }
  }

  pills.forEach(function(p){
    p.addEventListener('click', function(){
      pills.forEach(function(x){ x.classList.remove('active'); });
      p.classList.add('active');
      currentFilter = p.getAttribute('data-filter');
      applyFilters();
    });
  });

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }
})();
</script>
