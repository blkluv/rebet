<?php
/**
 * Plugin Name: Polla Mundial MVP
 * Description: MVP de polla deportiva (Mundial) con tablas MySQL personalizadas.
 * Version: 0.1.2
 * Author: David Sánchez
 */

if (!defined('ABSPATH')) exit;

class PollaMundialMVP {
  const CAPABILITY = 'manage_options';

  public function __construct() {
  add_action('admin_menu', [$this, 'register_admin_menu']);
  add_action('admin_init', [$this, 'register_admin_actions']);

  // Frontend: shortcodes y guardado
  add_action('init', [$this, 'register_shortcodes']);
  add_action('init', [$this, 'handle_prediction_submit']);
  add_action('init', [$this, 'handle_group_create']);
  add_action('init', [$this, 'handle_group_join']);
  //Accion para campeon y Goleador
  add_action('init', [$this, 'handle_special_predictions_submit']);

  //add_action('init', [$this, 'handle_admin_save_results']);

  
}


  public function register_admin_menu() {
    // Menú principal
    add_menu_page(
      'Polla Mundial',
      'Polla Mundial',
      self::CAPABILITY,
      'polla-mundial',
      [$this, 'render_dashboard'],
      'dashicons-tickets-alt',
      26
    );

    // Submenús
    add_submenu_page(
      'polla-mundial',
      'Dashboard',
      'Dashboard',
      self::CAPABILITY,
      'polla-mundial',
      [$this, 'render_dashboard']
    );

    add_submenu_page(
      'polla-mundial',
      'Equipos',
      'Equipos',
      self::CAPABILITY,
      'polla-mundial-teams',
      [$this, 'render_teams']
    );

    add_submenu_page(
      'polla-mundial',
      'Partidos',
      'Partidos',
      self::CAPABILITY,
      'polla-mundial-matches',
      [$this, 'render_matches']
    );

    add_submenu_page(
      'polla-mundial',
      'Ingresar resultados',
      'Ingresar resultados',
      self::CAPABILITY,
      'polla-mundial-results',
      [$this, 'render_results']
    );

    add_submenu_page(
     'polla-mundial',
     'Jugadores',
     'Jugadores',
     self::CAPABILITY,
     'polla-mundial-players',
     [$this, 'render_players_admin']
    );

    //Agregamos pagina admin para pronosticos especiales (campeon y goleador)

    add_submenu_page(
      'polla-mundial',
      'Predicciones Especiales',
      'Predicciones Especiales',
      self::CAPABILITY,
      'polla-mundial-special',
      [$this, 'render_special_admin']
   );
  }

  public function register_admin_actions() {
  if (!is_admin()) return;

  // Acción: agregar equipo
  if (isset($_POST['polla_add_team'])) {
    if (!current_user_can(self::CAPABILITY)) return;
    check_admin_referer('polla_add_team_action');

    $name  = sanitize_text_field($_POST['team_name'] ?? '');
    $short = sanitize_text_field($_POST['team_short'] ?? '');
    $logo  = esc_url_raw($_POST['team_logo_url'] ?? '');

    if ($name) {
      global $wpdb;
      $table = $wpdb->prefix . 'polla_teams';
      $wpdb->insert($table, [
        'name'       => $name,
        'short_name' => $short ?: null,
        'logo_url'   => $logo ?: null,
        'created_at' => current_time('mysql'),
      ]);
    }

    wp_safe_redirect(admin_url('admin.php?page=polla-mundial-teams'));
    exit;
  }

  // Acción: agregar partido
  if (isset($_POST['polla_add_match'])) {
    if (!current_user_can(self::CAPABILITY)) return;
    check_admin_referer('polla_add_match_action');

    global $wpdb;
    $matches_table = $wpdb->prefix . 'polla_matches';

    $home = intval($_POST['home_team'] ?? 0);
    $away = intval($_POST['away_team'] ?? 0);
    $dt   = sanitize_text_field($_POST['match_datetime'] ?? '');

    if ($home && $away && $home !== $away && $dt) {
      $timestamp = strtotime($dt);
      if ($timestamp) {
        $match_datetime = date('Y-m-d H:i:s', $timestamp);
        $close_datetime = date('Y-m-d H:i:s', $timestamp - 3600); // -1 hora

        $wpdb->insert($matches_table, [
          'home_team_id'   => $home,
          'away_team_id'   => $away,
          'match_datetime' => $match_datetime,
          'close_datetime' => $close_datetime,
          'status'         => 'scheduled',
          'created_at'     => current_time('mysql'),
        ]);
      }
    }

    wp_safe_redirect(admin_url('admin.php?page=polla-mundial-matches'));
    exit;
  }

}


  public function render_dashboard() {
    echo '<div class="wrap"><h1>Polla Mundial - Dashboard</h1>';
    echo '<p>Plugin activo ✅. Siguiente: cargar equipos, cargar partidos y luego construir pantalla de pronósticos.</p>';
    echo '</div>';
  }

  public function render_teams() {
    global $wpdb;
    $table = $wpdb->prefix . 'polla_teams';
    $teams = $wpdb->get_results("SELECT id, name, short_name, logo_url, created_at FROM $table ORDER BY id DESC LIMIT 200");

    echo '<div class="wrap"><h1>Equipos</h1>';

    // Formulario simple para agregar
    echo '<h2>Agregar equipo</h2>';
    echo '<form method="post">';
    wp_nonce_field('polla_add_team_action');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label>Nombre</label></th><td><input name="team_name" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th><label>Abreviación</label></th><td><input name="team_short" type="text" class="regular-text" placeholder="COL, ARG, RMA..."></td></tr>';
    echo '<tr><th><label>Logo URL (opcional)</label></th><td><input name="team_logo_url" type="url" class="regular-text" placeholder="https://..."></td></tr>';
    echo '</tbody></table>';
    echo '<p><button class="button button-primary" type="submit" name="polla_add_team" value="1">Guardar equipo</button></p>';
    echo '</form>';

    // Listado
    echo '<hr><h2>Listado</h2>';
    if (!$teams) {
      echo '<p>No hay equipos aún.</p></div>';
      return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>ID</th><th>Nombre</th><th>Short</th><th>Logo</th><th>Creado</th></tr></thead><tbody>';
    foreach ($teams as $t) {
      $logo = $t->logo_url ? '<a href="' . esc_url($t->logo_url) . '" target="_blank">ver</a>' : '-';
      echo '<tr>';
      echo '<td>' . intval($t->id) . '</td>';
      echo '<td>' . esc_html($t->name) . '</td>';
      echo '<td>' . esc_html($t->short_name ?? '') . '</td>';
      echo '<td>' . $logo . '</td>';
      echo '<td>' . esc_html($t->created_at) . '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
  }

  public function render_matches() {
  global $wpdb;
  $teams_table   = $wpdb->prefix . 'polla_teams';
  $matches_table = $wpdb->prefix . 'polla_matches';

  $teams = $wpdb->get_results("SELECT id, name FROM $teams_table ORDER BY name ASC");
  $matches = $wpdb->get_results("
    SELECT m.*, h.name AS home_name, a.name AS away_name
    FROM $matches_table m
    JOIN $teams_table h ON h.id = m.home_team_id
    JOIN $teams_table a ON a.id = m.away_team_id
    ORDER BY m.match_datetime DESC
  ");

  echo '<div class="wrap"><h1>Partidos</h1>';

  // Formulario
  echo '<h2>Crear partido</h2>';
  echo '<form method="post">';
  wp_nonce_field('polla_add_match_action');

  echo '<table class="form-table"><tbody>';
  echo '<tr><th>Local</th><td><select name="home_team" required>';
  echo '<option value="">Seleccione</option>';
  foreach ($teams as $t) {
    echo '<option value="' . intval($t->id) . '">' . esc_html($t->name) . '</option>';
  }
  echo '</select></td></tr>';

  echo '<tr><th>Visitante</th><td><select name="away_team" required>';
  echo '<option value="">Seleccione</option>';
  foreach ($teams as $t) {
    echo '<option value="' . intval($t->id) . '">' . esc_html($t->name) . '</option>';
  }
  echo '</select></td></tr>';

  echo '<tr><th>Fecha y hora</th><td><input type="datetime-local" name="match_datetime" required></td></tr>';
  echo '</tbody></table>';

  echo '<p><button class="button button-primary" name="polla_add_match" value="1">Guardar partido</button></p>';
  echo '</form>';

  // Listado
  echo '<hr><h2>Listado</h2>';

  if (!$matches) {
    echo '<p>No hay partidos aún.</p></div>';
    return;
  }

  echo '<table class="widefat striped">';
  echo '<thead><tr><th>ID</th><th>Partido</th><th>Fecha</th><th>Cierre</th><th>Estado</th></tr></thead><tbody>';

  foreach ($matches as $m) {
    echo '<tr>';
    echo '<td>' . intval($m->id) . '</td>';
    echo '<td>' . esc_html($m->home_name . ' vs ' . $m->away_name) . '</td>';
    echo '<td>' . esc_html($m->match_datetime) . '</td>';
    echo '<td>' . esc_html($m->close_datetime) . '</td>';
    echo '<td>' . esc_html($m->status) . '</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
}



  public function render_results() {
  global $wpdb;
  $matches_table = $wpdb->prefix . 'polla_matches';
  $teams_table   = $wpdb->prefix . 'polla_teams';

  // Guardar resultado
  if (isset($_POST['polla_save_result'])) {
    check_admin_referer('polla_save_result_action');

    $match_id = intval($_POST['match_id']);
    $rh = intval($_POST['real_home']);
    $ra = intval($_POST['real_away']);

    if ($match_id > 0) {
     $wpdb->update(
  $wpdb->prefix . 'polla_matches',
  [
    'home_score' => (int) $rh,
    'away_score' => (int) $ra,
    'status'     => 'finished',
    'updated_at' => current_time('mysql'),
  ],
  ['id' => (int) $match_id],
  ['%d','%d','%s','%s'],
  ['%d']
);

      // 🔥 calcular puntos
      $this->calculate_points_for_match($match_id);
    }

    wp_safe_redirect(admin_url('admin.php?page=polla-mundial-results'));
    exit;
  }

  // Traer partidos cerrados sin resultado
  $matches = $wpdb->get_results("
    SELECT m.*, h.name AS home, a.name AS away
    FROM $matches_table m
    JOIN $teams_table h ON h.id = m.home_team_id
    JOIN $teams_table a ON a.id = m.away_team_id
    WHERE m.status = 'scheduled'
      AND m.close_datetime <= NOW()
    ORDER BY m.match_datetime ASC
  ");

  echo '<div class="wrap"><h1>Ingresar resultados</h1>';

  if (!$matches) {
    echo '<p>No hay partidos pendientes de resultado.</p></div>';
    return;
  }

  echo '<table class="widefat striped">';
  echo '<thead><tr><th>Partido</th><th>Resultado</th><th>Acción</th></tr></thead><tbody>';

  foreach ($matches as $m) {
    echo '<tr><form method="post">';
    wp_nonce_field('polla_save_result_action');

    echo '<td>' . esc_html($m->home . ' vs ' . $m->away) . '</td>';
    echo '<td>
      <input type="number" name="real_home" min="0" style="width:60px" required> :
      <input type="number" name="real_away" min="0" style="width:60px" required>
    </td>';
    echo '<td>
      <input type="hidden" name="match_id" value="'.intval($m->id).'">
      <button class="button button-primary" name="polla_save_result">Guardar</button>
    </td>';

    echo '</form></tr>';
  }

  echo '</tbody></table></div>';
}
// Función para calcular puntos de un partido dado su ID
private function calculate_points_for_match($match_id) {
  global $wpdb;

  $pred_table  = $wpdb->prefix . 'polla_predictions';
  $score_table = $wpdb->prefix . 'polla_prediction_scores';
  $match_table = $wpdb->prefix . 'polla_matches';

  // 1) Traer resultado real (en tu BD: home_score / away_score)
  $match = $wpdb->get_row(
    $wpdb->prepare(
      "SELECT id, home_score, away_score, status
       FROM $match_table
       WHERE id = %d",
      $match_id
    )
  );

  if (!$match) return;

  // Si aún no hay marcador cargado, no calcules puntos
  if ($match->home_score === null || $match->away_score === null) return;

  $real_home = (int) $match->home_score;
  $real_away = (int) $match->away_score;

  // 2) Traer pronósticos de ese partido
  $preds = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, pred_home_goals, pred_away_goals
       FROM $pred_table
       WHERE match_id = %d",
      $match_id
    )
  );

  if (!$preds) return;

  // 3) Evitar duplicados: borrar puntos previos de ese partido
  $wpdb->query(
    $wpdb->prepare(
      "DELETE s FROM $score_table s
       JOIN $pred_table p ON p.id = s.prediction_id
       WHERE p.match_id = %d",
      $match_id
    )
  );

  // 4) Calcular e insertar puntos
  foreach ($preds as $p) {

    // ✅ Inicializar SIEMPRE
    $points_result = 0;
    $points_exact  = 0;
    $points_bonus  = 0;

    $pred_home = (int) $p->pred_home_goals;
    $pred_away = (int) $p->pred_away_goals;

    // 🎯 PLENO (marcador exacto)
    if ($pred_home === $real_home && $pred_away === $real_away) {
        $points_exact = 12;
    } else {

        // Ganador / empate correcto
        $pred_diff = $pred_home - $pred_away;
        $real_diff = $real_home - $real_away;

        if (
            ($pred_diff > 0 && $real_diff > 0) ||
            ($pred_diff < 0 && $real_diff < 0) ||
            ($pred_diff === 0 && $real_diff === 0)
        ) {
            $points_result += 5;
        }

        // Goles exactos por equipo
        if ($pred_home === $real_home) {
            $points_result += 2;
        }

        if ($pred_away === $real_away) {
            $points_result += 2;
        }
    }

    $points_total = $points_result + $points_exact + $points_bonus;

    $wpdb->insert(
        $score_table,
        [
            'prediction_id' => (int) $p->id,
            'points_total'  => (int) $points_total,
            'points_result' => (int) $points_result,
            'points_exact'  => (int) $points_exact,
            'points_bonus'  => (int) $points_bonus,
            'calculated_at' => current_time('mysql'),
        ],
        ['%d','%d','%d','%d','%d','%s']
    );
}
  // 5) Actualizar leaderboard (si tu función depende solo de scores)
  $this->update_leaderboard_global_for_match($match_id);
}

private function update_leaderboard_global_for_match($match_id) {
  global $wpdb;

  $pred_table  = $wpdb->prefix . 'polla_predictions';
  $score_table = $wpdb->prefix . 'polla_prediction_scores';
  $lb_table    = $wpdb->prefix . 'polla_leaderboard_global';

  // Usuarios que participaron en ese partido
  $user_ids = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT DISTINCT user_id
       FROM $pred_table
       WHERE match_id = %d",
      $match_id
    )
  );

  if (!$user_ids) return;

  foreach ($user_ids as $user_id) {
    $user_id = (int)$user_id;

    // Total de puntos del usuario (sumando TODO lo calculado)
    $total = $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COALESCE(SUM(s.points_total), 0)
         FROM $pred_table p
         JOIN $score_table s ON s.prediction_id = p.id
         WHERE p.user_id = %d",
        $user_id
      )
    );

    $total = (int)$total;

    // Upsert en leaderboard_global
    // REPLACE requiere UNIQUE/PK en user_id
    $wpdb->replace(
  $lb_table,
  [
    'user_id'      => (int) $user_id,
    'points_total' => (int) $total,
    'updated_at'   => current_time('mysql'),
  ],
  ['%d','%d','%s']
);
  }

  
}


  public function register_shortcodes() {
  add_shortcode('polla_pronosticos', [$this, 'shortcode_pronosticos']);
  add_shortcode('polla_leaderboard', [$this, 'shortcode_leaderboard_global']);
  add_shortcode('polla_grupos', [$this, 'shortcode_grupos']);

  // ✅ NUEVO
  add_shortcode('polla_mis_ligas', [$this, 'shortcode_mis_ligas']);
  add_shortcode('polla_leaderboard_liga', [$this, 'shortcode_leaderboard_liga']);
  add_shortcode('polla_home', [$this, 'shortcode_home']);
  //Polla especiales nuevo para el implemento de campeon y goleador 
  add_shortcode('polla_especiales', [$this, 'shortcode_especiales']);
  // Reglas
  add_shortcode('polla_reglas', [$this, 'shortcode_reglas']);


}



private function get_warnings_html(array $warnings): string {
  if (empty($warnings)) return '';
  $out = '<div class="notice notice-warning" style="padding:12px; border-left:4px solid #dba617; background:#fff8e5; margin:12px 0;">';
  $out .= '<strong>Ojo:</strong><ul style="margin:8px 0 0 18px;">';
  foreach ($warnings as $w) {
    $out .= '<li>' . esc_html($w) . '</li>';
  }
  $out .= '</ul></div>';
  return $out;
}

public function handle_admin_save_results() {
  if (!isset($_POST['polla_save_results'])) return;

  if (!current_user_can('manage_options')) {
    wp_die('No autorizado.');
  }

  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'polla_save_results_action')) {
    wp_die('Nonce inválido. Recarga la página e intenta de nuevo.');
  }

  global $wpdb;
  $matches_table = $wpdb->prefix . 'polla_matches';

  $results = $_POST['res'] ?? []; // res[match_id][h], res[match_id][a]
  if (!$results) {
    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  foreach ($results as $match_id_raw => $vals) {
    $match_id = (int) $match_id_raw;

    // si no viene completo, no guardamos
    if (!isset($vals['h']) || !isset($vals['a'])) continue;

    $h = (int) $vals['h'];
    $a = (int) $vals['a'];

    // validación básica
    if ($h < 0 || $h > 30 || $a < 0 || $a > 30) continue;

    $wpdb->update(
      $matches_table,
      [
        'home_score' => $h,
        'away_score' => $a,
        'status'     => 'finished',
        'updated_at' => current_time('mysql'),
      ],
      ['id' => $match_id],
      ['%d','%d','%s','%s'],
      ['%d']
    );

    // recalcular puntos del partido
    $this->calculate_points_for_match($match_id);
  }

  $ref = wp_get_referer() ?: home_url('/');
  wp_safe_redirect(add_query_arg('results_saved', '1', $ref));
  exit;
}

/**
 * Procesa el POST del formulario de pronósticos (frontend).
 * Guarda/actualiza en wp_polla_predictions.
 */
public function handle_prediction_submit() {
  if (!isset($_POST['polla_save_predictions'])) return;

  if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(wp_get_referer() ?: home_url('/')));
    exit;
  }

  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'polla_save_predictions_action')) {
    wp_die('Nonce inválido. Recarga la página e intenta de nuevo.');
  }

  global $wpdb;
  $matches_table = $wpdb->prefix . 'polla_matches';
  $pred_table    = $wpdb->prefix . 'polla_predictions';

  $user_id = get_current_user_id();
  $preds   = $_POST['pred'] ?? [];

  // Guardaremos warnings en querystring para mostrarlos en pantalla
  $warnings = [];

  foreach ($preds as $match_id_raw => $values) {
    $match_id = intval($match_id_raw);
    $ph = isset($values['h']) ? intval($values['h']) : null;
    $pa = isset($values['a']) ? intval($values['a']) : null;

    // si no puso nada en alguno de los dos, saltamos
    if ($ph === null || $pa === null) continue;

    // Validación básica (ajústala si quieres)
    if ($ph < 0 || $ph > 20 || $pa < 0 || $pa > 20) {
      $warnings[] = "Marcador inválido en partido #$match_id (usa valores 0-20).";
      continue;
    }

    // Revisar que el partido exista y esté abierto (NOW < close_datetime)
    $match = $wpdb->get_row(
      $wpdb->prepare("SELECT id, close_datetime FROM $matches_table WHERE id = %d", $match_id)
    );

    if (!$match) {
      $warnings[] = "Partido #$match_id no existe.";
      continue;
    }

    $now_ts   = current_time('timestamp');
    $close_ts = strtotime($match->close_datetime);

    if ($now_ts >= $close_ts) {
      $warnings[] = "El partido #$match_id ya cerró (no se puede modificar).";
      continue;
    }

    // Upsert: si existe (user_id, match_id) actualiza; si no, inserta
    $existing_id = $wpdb->get_var(
      $wpdb->prepare("SELECT id FROM $pred_table WHERE user_id = %d AND match_id = %d", $user_id, $match_id)
    );

    if ($existing_id) {
      $wpdb->update(
        $pred_table,
        [
          'pred_home_goals' => $ph,
          'pred_away_goals' => $pa,
          'updated_at'      => current_time('mysql'),
        ],
        ['id' => intval($existing_id)],
        ['%d','%d','%s'],
        ['%d']
      );
    } else {
      $wpdb->insert(
        $pred_table,
        [
          'user_id'         => $user_id,
          'match_id'        => $match_id,
          'pred_home_goals' => $ph,
          'pred_away_goals' => $pa,
          'locked_at'       => null,
          'created_at'      => current_time('mysql'),
          'updated_at'      => current_time('mysql'),
        ],
        ['%d','%d','%d','%d','%s','%s','%s']
      );
    }
  }

  // Redirigir con estado
  $ref = wp_get_referer() ?: home_url('/');
  $url = add_query_arg('polla_saved', '1', $ref);

  if (!empty($warnings)) {
    // encode simple: guardamos cantidad de warnings (y los mostramos por sesión en transient)
    $key = 'polla_warn_' . $user_id . '_' . time();
    set_transient($key, $warnings, 60); // 60s
    $url = add_query_arg('polla_warn', $key, $url);
  }

  wp_safe_redirect($url);
  exit;
}

/**
 * Shortcode: [polla_pronosticos]
 * Lista partidos y permite guardar pronósticos antes del cierre.
 */

/* Estructura de tabla wp_polla_predictions:
- id (PK)
- user_id (INT)
- match_id (INT)
- pred_home_goals (INT)
- pred_away_goals (INT)
- locked_at (DATETIME) -> para bloquear edición después del cierre
- created_at (DATETIME)
- updated_at (DATETIME)
*/
public function shortcode_pronosticos() {
  if (!is_user_logged_in()) {
    $login_url = wp_login_url(get_permalink());
    return '<p>Debes iniciar sesión para hacer pronósticos. <a href="' . esc_url($login_url) . '">Iniciar sesión</a></p>';
  }

  global $wpdb;

  $teams_table   = $wpdb->prefix . 'polla_teams';
  $matches_table = $wpdb->prefix . 'polla_matches';
  $pred_table    = $wpdb->prefix . 'polla_predictions';
  $score_table   = $wpdb->prefix . 'polla_prediction_scores';

  $user_id = get_current_user_id();
  $now_ts  = current_time('timestamp');

  // Helper URL por título
  $get_url = function(string $title, string $fallback = '/') : string {
    $q = new WP_Query([
      'post_type'              => 'page',
      'post_status'            => 'publish',
      'title'                  => $title,
      'posts_per_page'         => 1,
      'no_found_rows'          => true,
      'ignore_sticky_posts'    => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'fields'                 => 'ids',
    ]);

    if (!empty($q->posts)) {
      return get_permalink((int)$q->posts[0]);
    }

    return home_url($fallback);
  };

  $url_ranking_global = $get_url('Ranking Global', '/ranking-global/');

  // Traer partidos con resultado real
  $matches = $wpdb->get_results("
    SELECT 
      m.id,
      m.match_datetime,
      m.close_datetime,
      m.status,
      m.home_score,
      m.away_score,
      h.name AS home_name,
      h.logo_url AS home_logo,
      a.name AS away_name,
      a.logo_url AS away_logo
    FROM $matches_table m
    JOIN $teams_table h ON h.id = m.home_team_id
    JOIN $teams_table a ON a.id = m.away_team_id
    ORDER BY m.match_datetime ASC
  ");

  if (!$matches) {
    return '<div class="polla-page">
      <div class="polla-hero">
        <h2 class="polla-title">Pronósticos 🔥</h2>
        <p class="polla-subtitle">No hay partidos cargados aún.</p>
      </div>
    </div>';
  }

  // Traer pronósticos del usuario + puntos del partido
  $user_preds = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT 
         p.match_id,
         p.pred_home_goals,
         p.pred_away_goals,
         s.points_total
       FROM $pred_table p
       LEFT JOIN $score_table s
         ON s.prediction_id = p.id
       WHERE p.user_id = %d",
      $user_id
    )
  );

  $pred_map = [];
  foreach ($user_preds as $p) {
    $pred_map[(int)$p->match_id] = [
      'h'      => ($p->pred_home_goals !== null) ? (int)$p->pred_home_goals : '',
      'a'      => ($p->pred_away_goals !== null) ? (int)$p->pred_away_goals : '',
      'points' => ($p->points_total !== null) ? (int)$p->points_total : null,
    ];
  }

  $html = '';

  if (isset($_GET['polla_saved']) && $_GET['polla_saved'] === '1') {
    $html .= '<div class="polla-alert polla-alert--ok">✅ Pronósticos guardados.</div>';
  }

  if (!empty($_GET['polla_warn'])) {
    $key = sanitize_text_field($_GET['polla_warn']);
    $warnings = get_transient($key);
    if (is_array($warnings)) {
      $html .= $this->get_warnings_html($warnings);
      delete_transient($key);
    }
  }

  $html .= '<div class="polla-page">';
  $html .= '  <div class="polla-hero">';
  $html .= '    <h2 class="polla-title">Pronósticos 🔥</h2>';
  $html .= '    <p class="polla-subtitle">Elige marcadores antes del cierre y suma puntos.</p>';
  $html .= '  </div>';

  $html .= '  <div class="polla-card polla-card--glow">';
  $html .= '    <form method="post" class="polla-form">';
  $html .=          wp_nonce_field('polla_save_predictions_action', '_wpnonce', true, false);
  $html .= '      <input type="hidden" name="polla_save_predictions" value="1">';

  $html .= '      <div class="polla-table-wrap">';
  $html .= '        <table class="polla-table">';
  $html .= '          <thead>';
  $html .= '            <tr>';
  $html .= '              <th class="col-date">Fecha</th>';
  $html .= '              <th class="col-match">Partido / Resultado</th>';
  $html .= '              <th class="col-status">Estado</th>';
  $html .= '            </tr>';
  $html .= '          </thead>';
  $html .= '          <tbody>';

  foreach ($matches as $m) {
    $match_id = (int)$m->id;
    $close_ts = strtotime($m->close_datetime);
    $is_open  = ($now_ts < $close_ts);

    $ph_val = isset($pred_map[$match_id]) ? $pred_map[$match_id]['h'] : '';
    $pa_val = isset($pred_map[$match_id]) ? $pred_map[$match_id]['a'] : '';
    $points_val = isset($pred_map[$match_id]) ? $pred_map[$match_id]['points'] : null;

    $real_home = ($m->home_score !== null) ? (int)$m->home_score : null;
    $real_away = ($m->away_score !== null) ? (int)$m->away_score : null;

    $home_logo = !empty($m->home_logo)
      ? '<img class="polla-team-logo" src="' . esc_url($m->home_logo) . '" alt="">'
      : '';

    $away_logo = !empty($m->away_logo)
      ? '<img class="polla-team-logo" src="' . esc_url($m->away_logo) . '" alt="">'
      : '';

    $html .= '<tr class="polla-row">';

    // FECHA
    $html .= '  <td class="col-date">';/*
    $html .= '    <div class="polla-date">' . esc_html($m->match_datetime) . '</div>';
    $html .= '    <div class="polla-close">Cierra: ' . esc_html($m->close_datetime) . '</div>';
    */
    $match_time = date_i18n('j M · H:i', strtotime($m->match_datetime));
    $close_time = date_i18n('H:i', strtotime($m->close_datetime));

    $html .= '    <div class="polla-date">' . esc_html($match_time) . '</div>';
    $html .= '    <div class="polla-close">⏳ Cierra: ' . esc_html($close_time) . '</div>';
    $html .= '  </td>';

    // PARTIDO + RESULTADO
    $html .= '  <td class="col-match">';
    $html .= '    <div class="polla-match-row">';

    $html .= '      <div class="polla-team left">';
    $html .=            $home_logo;
    $html .= '        <span class="polla-team-name">' . esc_html($m->home_name) . '</span>';
    $html .= '      </div>';

    $html .= '      <div class="polla-mid">';

    if ($is_open) {
      $html .= '        <input class="polla-input polla-score" type="number" min="0" max="20" name="pred['.$match_id.'][h]" value="'.esc_attr($ph_val).'">';
      $html .= '        <span class="polla-vs">vs</span>';
      $html .= '        <input class="polla-input polla-score" type="number" min="0" max="20" name="pred['.$match_id.'][a]" value="'.esc_attr($pa_val).'">';
    } else {
      $shown = ($ph_val !== '' && $pa_val !== '')
        ? esc_html($ph_val . ' : ' . $pa_val)
        : '—';

      $html .= '        <div class="polla-locked-wrap">';
      $html .= '          <span class="polla-locked-score">'.$shown.'</span>';

      if ($real_home !== null && $real_away !== null) {
        $html .= '        <div class="polla-real-score">Resultado real: <strong>'.$real_home.' : '.$real_away.'</strong></div>';
      }

      if ($points_val !== null) {
        $html .= '        <div class="polla-points-earned">🏅 '.$points_val.' pts</div>';
      }

      $html .= '        </div>';
    }

    $html .= '      </div>';

    $html .= '      <div class="polla-team right">';
    $html .= '        <span class="polla-team-name">' . esc_html($m->away_name) . '</span>';
    $html .=            $away_logo;
    $html .= '      </div>';

    $html .= '    </div>';
    $html .= '  </td>';

    // ESTADO
    $html .= '  <td class="col-status">';
    if ($is_open) {
      $html .= '    <span class="polla-pill polla-pill--open">Abierto</span>';
    } else {
      $html .= '    <span class="polla-pill polla-pill--closed">Cerrado</span>';
    }
    $html .= '  </td>';

    $html .= '</tr>';
  }

  $html .= '          </tbody>';
  $html .= '        </table>';
  $html .= '      </div>';

  $html .= '      <div class="polla-form-actions">';
  $html .= '        <button class="polla-btn" type="submit">Guardar pronósticos</button>';
  $html .= '        <a class="polla-btn polla-btn--ghost" href="'.esc_url($url_ranking_global).'">Ver ranking</a>';
  $html .= '      </div>';

  $html .= '    </form>';
  $html .= '  </div>';
  $html .= '</div>';

  return $html;
}


//Handler para guardar especiales 
public function handle_special_predictions_submit() {
  if (!isset($_POST['polla_save_special'])) return;

  if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(wp_get_referer()));
    exit;
  }

  if (!isset($_POST['_wpnonce']) || 
      !wp_verify_nonce($_POST['_wpnonce'], 'polla_save_special_action')) {
    wp_die('Nonce inválido.');
  }

  // 🔒 Cierre automático 11 junio 12:00 PM Colombia
  $lock_datetime = '2026-06-11 12:00:00';
  $now = current_time('timestamp');
  $lock_ts = strtotime($lock_datetime);

  if ($now >= $lock_ts) {
    wp_safe_redirect(add_query_arg('special_closed', '1', wp_get_referer()));
    exit;
  }

  global $wpdb;
  $table = $wpdb->prefix . 'polla_special_predictions';

  $user_id   = get_current_user_id();
  $champion  = intval($_POST['champion']);
  $runnerup  = intval($_POST['runnerup']);
  $third     = intval($_POST['third']);
  $scorer    = intval($_POST['scorer']);

  // Validar duplicados
  $teams = [$champion, $runnerup, $third];
  if (count(array_unique($teams)) < 3) {
    wp_safe_redirect(add_query_arg('special_error', 'duplicate', wp_get_referer()));
    exit;
  }

  $existing = $wpdb->get_var(
    $wpdb->prepare("SELECT id FROM $table WHERE user_id = %d", $user_id)
  );

  if ($existing) {
    $wpdb->update(
      $table,
      [
        'champion_team_id'      => $champion,
        'runner_up_team_id'     => $runnerup,
        'third_place_team_id'   => $third,
        'top_scorer_player_id'  => $scorer,
        'updated_at'            => current_time('mysql')
      ],
      ['user_id' => $user_id],
      ['%d','%d','%d','%d','%s'],
      ['%d']
    );
  } else {
    $wpdb->insert(
      $table,
      [
        'user_id'               => $user_id,
        'champion_team_id'      => $champion,
        'runner_up_team_id'     => $runnerup,
        'third_place_team_id'   => $third,
        'top_scorer_player_id'  => $scorer,
        'created_at'            => current_time('mysql'),
        'updated_at'            => current_time('mysql')
      ],
      ['%d','%d','%d','%d','%s','%s']
    );
  }

  wp_safe_redirect(add_query_arg('special_saved', '1', wp_get_referer()));
  exit;
}

// Metodo shortcode para mostrar especiales
public function shortcode_especiales() {

  if (!is_user_logged_in()) {
    return '<p>Debes iniciar sesión para hacer tus predicciones especiales.</p>';
  }

  global $wpdb;

  $teams_table    = $wpdb->prefix . 'polla_teams';
  $players_table  = $wpdb->prefix . 'polla_players';
  $special_table  = $wpdb->prefix . 'polla_special_predictions';

  $user_id = get_current_user_id();

  // 🔒 Lock automático (hora Colombia fija)
  try {
    $tz = new DateTimeZone('America/Bogota');
    $lock_dt = new DateTime('2026-06-11 12:00:00', $tz);
    $lock_ts = $lock_dt->getTimestamp();
  } catch (Exception $e) {
    // fallback seguro
    $lock_ts = strtotime('2026-06-11 12:00:00');
  }

  $now_ts  = time(); // timestamp del servidor (solo para comparar con lock_ts absoluto)
  $is_open = ($now_ts < $lock_ts);

  // ✅ Guardar (solo si está abierto)
  /*
  if (isset($_POST['polla_save_special']) && $_POST['polla_save_special'] == '1') {

    // Si ya cerró, no guardamos (blindado aunque hagan POST)
    if (!$is_open) {
      $ref = wp_get_referer() ?: home_url('/');
      wp_safe_redirect(add_query_arg('special_error', 'closed', $ref));
      exit;
    }

    // Nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'polla_save_special_action')) {
      wp_die('Nonce inválido. Recarga la página e intenta de nuevo.');
    }

    $champion = isset($_POST['champion']) ? intval($_POST['champion']) : 0;
    $runnerup = isset($_POST['runnerup']) ? intval($_POST['runnerup']) : 0;
    $third    = isset($_POST['third']) ? intval($_POST['third']) : 0;
    $scorer   = isset($_POST['scorer']) ? intval($_POST['scorer']) : 0;

    // Validación: no repetir equipos en podio
    if (
      !$champion || !$runnerup || !$third || !$scorer ||
      $champion === $runnerup ||
      $champion === $third ||
      $runnerup === $third
    ) {
      $ref = wp_get_referer() ?: home_url('/');
      wp_safe_redirect(add_query_arg('special_error', 'dup', $ref));
      exit;
    }

    // Upsert (1 fila por user_id)
    $exists_id = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $special_table WHERE user_id = %d",
      $user_id
    ));

    $data = [
      'champion_team_id'     => $champion,
      'runner_up_team_id'    => $runnerup,
      'third_place_team_id'  => $third,
      'top_scorer_player_id' => $scorer,
      'locked_at'            => null,
      'updated_at'           => current_time('mysql'),
    ];

    if ($exists_id) {
      $wpdb->update(
        $special_table,
        $data,
        ['id' => intval($exists_id)],
        ['%d','%d','%d','%d','%s','%s'],
        ['%d']
      );
    } else {
      $data['user_id']    = $user_id;
      $data['created_at'] = current_time('mysql');

      $wpdb->insert(
        $special_table,
        $data,
        ['%d','%d','%d','%d','%s','%s','%d','%s']
      );
    }

    $ref = wp_get_referer() ?: home_url('/');
    wp_safe_redirect(add_query_arg('special_saved', '1', remove_query_arg(['special_error'], $ref)));
    exit;
  }*/

  // Equipos ordenados
  $teams = $wpdb->get_results("SELECT id, name FROM $teams_table ORDER BY name ASC");

  // Jugadores activos con nombre del equipo
  $players = $wpdb->get_results("
    SELECT p.id, p.name, t.name AS team_name
    FROM $players_table p
    LEFT JOIN $teams_table t ON t.id = p.team_id
    WHERE p.is_active = 1
    ORDER BY p.name ASC
  ");

  // Predicción actual del usuario
  $current = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM $special_table WHERE user_id = %d", $user_id)
  );
  $curr_champ   = $current ? (int)$current->champion_team_id : 0;
  $curr_runner  = $current ? (int)$current->runner_up_team_id : 0;
  $curr_third   = $current ? (int)$current->third_place_team_id : 0;
  $curr_scorer  = $current ? (int)$current->top_scorer_player_id : 0;

  ob_start();
  ?>

  <div class="polla-page">
    <div class="polla-hero">
      <h2 class="polla-title">Predicciones Especiales 🏆</h2>
      <p class="polla-subtitle">
        Cierra el 11 de junio a las 12:00 PM (hora Colombia).
      </p>
    </div>

    <?php if (!$is_open): ?>
      <div class="polla-alert polla-alert--closed">
        🔒 Las predicciones especiales ya están cerradas.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['special_saved'])): ?>
      <div class="polla-alert polla-alert--ok">
        ✅ Predicciones guardadas correctamente.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['special_error']) && $_GET['special_error'] === 'dup'): ?>
      <div class="polla-alert polla-alert--error">
        ❌ No puedes repetir equipos en campeón/subcampeón/tercer puesto.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['special_error']) && $_GET['special_error'] === 'closed'): ?>
      <div class="polla-alert polla-alert--error">
        ⛔ Ya cerró el tiempo. No puedes guardar cambios.
      </div>
    <?php endif; ?>

    <div class="polla-card polla-card--glow">
      <form method="post">
        <?php wp_nonce_field('polla_save_special_action'); ?>
        <input type="hidden" name="polla_save_special" value="1">

        <div class="polla-form-grid">

          <div>
            <label>Campeón</label>
            <select name="champion" required <?php disabled(!$is_open); ?>>
              <option value="">Seleccione</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?php echo (int)$t->id; ?>"
                  <?php selected($curr_champ, (int)$t->id); ?>>
                  <?php echo esc_html($t->name); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Subcampeón</label>
            <select name="runnerup" required <?php disabled(!$is_open); ?>>
              <option value="">Seleccione</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?php echo (int)$t->id; ?>"
                  <?php selected($curr_runner, (int)$t->id); ?>>
                  <?php echo esc_html($t->name); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Tercer puesto</label>
            <select name="third" required <?php disabled(!$is_open); ?>>
              <option value="">Seleccione</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?php echo (int)$t->id; ?>"
                  <?php selected($curr_third, (int)$t->id); ?>>
                  <?php echo esc_html($t->name); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Goleador</label>
            <select name="scorer" required <?php disabled(!$is_open); ?>>
              <option value="">Seleccione</option>
              <?php foreach ($players as $p): ?>
                <option value="<?php echo (int)$p->id; ?>"
                  <?php selected($curr_scorer, (int)$p->id); ?>>
                  <?php echo esc_html($p->name . ' (' . $p->team_name . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>

        <?php if ($is_open): ?>
          <div class="polla-form-actions">
            <button class="polla-btn" type="submit">
              Guardar predicciones
            </button>
          </div>
        <?php endif; ?>

      </form>
    </div>
  </div>

  <?php
  return ob_get_clean();
}

public function shortcode_reglas() {
  $html  = '<section class="polla-page">';
  $html .= '  <div class="polla-hero">';
  $html .= '    <h1 class="polla-title">Reglas 📜</h1>';
  $html .= '    <p class="polla-subtitle">Así se ganan puntos y así funciona la polla.</p>';
  $html .= '  </div>';

  $html .= '  <div class="polla-card polla-card--glow">';
  $html .= '    <div class="polla-rules">';

  $html .= '      <h3>🎯 Pronósticos por partido</h3>';
  $html .= '      <ul class="polla-list">';
  $html .= '        <li class="polla-list-item"><strong>Pleno (marcador exacto):</strong> 12 puntos </li>';
  $html .= '        <li class="polla-list-item"><strong>Acertar ganador/empate:</strong> 5 puntos</li>';
  $html .= '        <li class="polla-list-item"><strong>Acertar goles del local:</strong> +2 puntos</li>';
  $html .= '        <li class="polla-list-item"><strong>Acertar goles del visitante:</strong> +2 puntos</li>';
  $html .= '      </ul>';

  $html .= '      <div class="polla-alert polla-alert--info" style="margin-top:12px;">';
  $html .= '        ⏳ <strong>Cierre:</strong> no puedes editar un partido cuando llega su hora de cierre.';
  $html .= '      </div>';

  $html .= '      <h3 style="margin-top:18px;">🏆 Predicciones especiales</h3>';
  $html .= '      <ul class="polla-list">';
  $html .= '        <li class="polla-list-item"><strong>Campeón:</strong> 30 puntos</li>';
  $html .= '        <li class="polla-list-item"><strong>Subcampeón:</strong> 30 puntos</li>';
  $html .= '        <li class="polla-list-item"><strong>Tercer puesto:</strong> 30 puntos</li>';
  $html .= '        <li class="polla-list-item"><strong>Goleador:</strong> 30 puntos</li>';
  $html .= '      </ul>';
  $html .= '      <p class="polla-muted" style="margin-top:8px;">No puedes repetir equipos en campeón/subcampeón/tercer puesto.</p>';

  $html .= '      <h3 style="margin-top:18px;">👥 Ligas</h3>';
  $html .= '      <ul class="polla-list">';
  $html .= '        <li class="polla-list-item">Las ligas son para competir con amigos.</li>';
  $html .= '        <li class="polla-list-item">El ranking por liga muestra solo miembros de esa liga.</li>';
  $html .= '      </ul>';

  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '</section>';

  return $html;
}

//Metodo pagina admin completa para mostrar especiales
// Metodo pagina admin completa para mostrar especiales (TABLAS)
public function render_special_admin() {
  if (!current_user_can(self::CAPABILITY)) return;

  global $wpdb;

  $teams_table    = $wpdb->prefix . 'polla_teams';
  $players_table  = $wpdb->prefix . 'polla_players';
  $results_table  = $wpdb->prefix . 'polla_special_results';

  $teams = $wpdb->get_results("SELECT id, name FROM $teams_table ORDER BY name ASC");
  $players = $wpdb->get_results("
    SELECT p.id, p.name, t.name AS team_name
    FROM $players_table p
    LEFT JOIN $teams_table t ON t.id = p.team_id
    WHERE p.is_active = 1
    ORDER BY p.name ASC
  ");

  // Asegurar fila única de resultados
  $res = $wpdb->get_row("SELECT * FROM $results_table WHERE id = 1");
  if (!$res) {
    $wpdb->insert($results_table, ['id'=>1,'updated_at'=>current_time('mysql')], ['%d','%s']);
    $res = $wpdb->get_row("SELECT * FROM $results_table WHERE id = 1");
  }

  echo '<div class="wrap"><h1>Resultados Especiales</h1>';

  // Guardar resultados reales (en tabla)
  if (isset($_POST['polla_save_special_results']) || isset($_POST['polla_calculate_special'])) {
    check_admin_referer('polla_special_admin_action');

    $champ = intval($_POST['real_champion'] ?? 0);
    $run   = intval($_POST['real_runnerup'] ?? 0);
    $third = intval($_POST['real_third'] ?? 0);
    $scor  = intval($_POST['real_scorer'] ?? 0);

    // Validación rápida: no duplicar equipos
    $team_ids = array_filter([$champ,$run,$third]);
    if (count($team_ids) !== count(array_unique($team_ids))) {
      echo '<div class="notice notice-error"><p>❌ Campeón/Subcampeón/Tercer puesto no pueden repetirse.</p></div>';
    } else {
      $wpdb->replace($results_table, [
        'id' => 1,
        'champion_team_id' => $champ ?: null,
        'runner_up_team_id' => $run ?: null,
        'third_place_team_id' => $third ?: null,
        'top_scorer_player_id' => $scor ?: null,
        'updated_at' => current_time('mysql'),
      ], ['%d','%d','%d','%d','%d','%s']);

      // refrescar res
      $res = $wpdb->get_row("SELECT * FROM $results_table WHERE id = 1");

      echo '<div class="notice notice-success"><p>✅ Resultados guardados.</p></div>';
    }
  }

  // Botón calcular
if (isset($_POST['polla_calculate_special'])) {
  // Ya validaste este mismo nonce arriba con polla_special_admin_action ✅
  $this->calculate_special_scores_for_all();
  echo '<div class="notice notice-success"><p>✅ Puntos especiales calculados correctamente.</p></div>';
}

  ?>
  <form method="post">
    <h2>Resultados oficiales</h2>

      

    <select name="real_champion" required>
      <option value="">Campeón</option>
      <?php foreach ($teams as $t): ?>
        <option value="<?php echo intval($t->id); ?>" <?php selected((int)($res->champion_team_id ?? 0), (int)$t->id); ?>>
          <?php echo esc_html($t->name); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="real_runnerup" required>
      <option value="">Subcampeón</option>
      <?php foreach ($teams as $t): ?>
        <option value="<?php echo intval($t->id); ?>" <?php selected((int)($res->runner_up_team_id ?? 0), (int)$t->id); ?>>
          <?php echo esc_html($t->name); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="real_third" required>
      <option value="">Tercer puesto</option>
      <?php foreach ($teams as $t): ?>
        <option value="<?php echo intval($t->id); ?>" <?php selected((int)($res->third_place_team_id ?? 0), (int)$t->id); ?>>
          <?php echo esc_html($t->name); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="real_scorer" required>
      <option value="">Goleador</option>
      <?php foreach ($players as $p): ?>
        <option value="<?php echo intval($p->id); ?>" <?php selected((int)($res->top_scorer_player_id ?? 0), (int)$p->id); ?>>
          <?php echo esc_html($p->name . ' (' . ($p->team_name ?? '—') . ')'); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <p>
      <button class="button button-primary" name="polla_save_special_results" value="1">
        Guardar resultados
      </button>
    </p>

    <hr>

    <h2>Calcular puntos</h2>

    <?php wp_nonce_field('polla_special_admin_action'); ?>

    <p>
      <button class="button button-secondary" name="polla_calculate_special" value="1">
        Calcular puntos especiales
      </button>
    </p>
  </form>
  </div>
  <?php
}

private function calculate_special_scores_for_all() {
  global $wpdb;

  $pred_table   = $wpdb->prefix . 'polla_special_predictions';
  $res_table    = $wpdb->prefix . 'polla_special_results';
  $scores_table = $wpdb->prefix . 'polla_special_scores';

  $res = $wpdb->get_row("SELECT * FROM $res_table WHERE id = 1");
  if (!$res) return;

  $preds = $wpdb->get_results("SELECT * FROM $pred_table");
  if (!$preds) return;

  foreach ($preds as $p) {
    $pc = ((int)$p->champion_team_id === (int)$res->champion_team_id) ? 30 : 0;
    $pr = ((int)$p->runner_up_team_id === (int)$res->runner_up_team_id) ? 30 : 0;
    $pt = ((int)$p->third_place_team_id === (int)$res->third_place_team_id) ? 30 : 0;
    $ps = ((int)$p->top_scorer_player_id === (int)$res->top_scorer_player_id) ? 30 : 0;

    $total = $pc + $pr + $pt + $ps;

    $wpdb->replace($scores_table, [
      'user_id'         => (int)$p->user_id,
      'points_total'    => $total,
      'points_champion' => $pc,
      'points_runnerup' => $pr,
      'points_third'    => $pt,
      'points_scorer'   => $ps,
      'calculated_at'   => current_time('mysql'),
    ], ['%d','%d','%d','%d','%d','%d','%s']);
  }
}



//Funcion para calcular puntos especiales
/*
private function calculate_special_points() {

  global $wpdb;

  $pred_table   = $wpdb->prefix . 'polla_special_predictions';
  $score_table  = $wpdb->prefix . 'polla_special_scores';

  $real_champion = intval(get_option('polla_real_champion'));
  $real_runnerup = intval(get_option('polla_real_runnerup'));
  $real_third    = intval(get_option('polla_real_third'));
  $real_scorer   = intval(get_option('polla_real_scorer'));

  $preds = $wpdb->get_results("SELECT * FROM $pred_table");

  foreach ($preds as $p) {

    $points_champion = ($p->champion_team_id == $real_champion) ? 30 : 0;
    $points_runnerup = ($p->runner_up_team_id == $real_runnerup) ? 30 : 0;
    $points_third    = ($p->third_place_team_id == $real_third) ? 30 : 0;
    $points_scorer   = ($p->top_scorer_player_id == $real_scorer) ? 30 : 0;

    $total = $points_champion + $points_runnerup + $points_third + $points_scorer;

    $wpdb->replace(
      $score_table,
      [
        'user_id'         => $p->user_id,
        'points_total'    => $total,
        'points_champion' => $points_champion,
        'points_runnerup' => $points_runnerup,
        'points_third'    => $points_third,
        'points_scorer'   => $points_scorer,
        'calculated_at'   => current_time('mysql'),
      ],
      ['%d','%d','%d','%d','%d','%d','%s']
    );
  }
}
*/




public function shortcode_leaderboard_global() {
  global $wpdb;

  $scores = $wpdb->get_results("
  SELECT 
    u.ID AS user_id,
    u.display_name AS user,
    COALESCE(SUM(s.points_total),0) + COALESCE(sp.points_total,0) AS total_points
  FROM {$wpdb->users} u
  LEFT JOIN {$wpdb->prefix}polla_predictions p ON p.user_id = u.ID
  LEFT JOIN {$wpdb->prefix}polla_prediction_scores s ON s.prediction_id = p.id
  LEFT JOIN {$wpdb->prefix}polla_special_scores sp ON sp.user_id = u.ID
  GROUP BY u.ID
  ORDER BY total_points DESC, u.display_name ASC
");

  if (!$scores) {
    return '<div class="polla-card"><p class="polla-muted">Aún no hay puntos calculados.</p></div>';
  }

  $html  = '<section class="polla-page">';
  $html .= '  <div class="polla-hero">';
  $html .= '    <h1 class="polla-title">Ranking Global 🏆</h1>';
  $html .= '    <p class="polla-subtitle">Competencia total: sube posiciones con cada acierto.</p>';
  $html .= '  </div>';

  $html .= '  <div class="polla-card polla-card--glow">';
  $html .= '    <div class="polla-table-wrap">';
  $html .= '      <table class="polla-table">';
  $html .= '        <thead><tr>';
  $html .= '          <th class="col-pos">#</th>';
  $html .= '          <th class="col-user">Usuario</th>';
  $html .= '          <th class="col-points">Puntos</th>';
  $html .= '        </tr></thead><tbody>';

  $pos = 1;
  $current_user = wp_get_current_user();
  foreach ($scores as $s) {

    $badge = '';
    if ($pos === 1) $badge = '<span class="polla-badge polla-badge--gold">🥇</span>';
    elseif ($pos === 2) $badge = '<span class="polla-badge polla-badge--silver">🥈</span>';
    elseif ($pos === 3) $badge = '<span class="polla-badge polla-badge--bronze">🥉</span>';

    $row_class = '';
    if ($pos === 1) $row_class = ' is-top is-top-1';
    elseif ($pos === 2) $row_class = ' is-top is-top-2';
    elseif ($pos === 3) $row_class = ' is-top is-top-3';

    // 🔥 NUEVO: detectar si es el usuario logueado
    $is_me = ($s->user === $current_user->display_name);
    $me_class = $is_me ? ' is-me' : '';

    $html .= '<tr class="polla-row'.$row_class.$me_class.'">';
    $html .= '  <td class="col-pos"><span class="polla-pos">'.$pos.'</span></td>';
    $html .= '  <td class="col-user"><span class="polla-user">'.esc_html($s->user).'</span>'.$badge.'</td>';
    $html .= '  <td class="col-points"><span class="polla-points">'.intval($s->total_points).'</span></td>';
    $html .= '</tr>';

    $pos++;
}

  $html .= '        </tbody></table>';
  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '</section>';

  return $html;
}

// Página admin Jugadores
public function render_players_admin() {
  if (!current_user_can(self::CAPABILITY)) return;

  global $wpdb;

  $players_table = $wpdb->prefix . 'polla_players';
  $teams_table   = $wpdb->prefix . 'polla_teams';

  $teams = $wpdb->get_results("SELECT id, name FROM $teams_table ORDER BY name ASC");

  echo '<div class="wrap"><h1>Jugadores</h1>';

  // Guardar jugador
  if (isset($_POST['polla_add_player'])) {
    check_admin_referer('polla_add_player_action');

    $name    = sanitize_text_field($_POST['player_name'] ?? '');
    $team_id = intval($_POST['team_id'] ?? 0);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    if ($name && $team_id) {
      $wpdb->insert($players_table, [
        'name'       => $name,
        'team_id'    => $team_id,
        'is_active'  => $active,
        'created_at' => current_time('mysql'),
      ], ['%s','%d','%d','%s']);

      echo '<div class="notice notice-success"><p>Jugador agregado correctamente.</p></div>';
    }
  }

  ?>

  <h2>Agregar jugador</h2>
  <form method="post">
    <?php wp_nonce_field('polla_add_player_action'); ?>

    <table class="form-table">
      <tr>
        <th>Nombre</th>
        <td>
          <input type="text" name="player_name" required style="width:300px;">
        </td>
      </tr>

      <tr>
        <th>Selección / Equipo</th>
        <td>
          <select name="team_id" required>
            <option value="">— Seleccione —</option>
            <?php foreach ($teams as $t): ?>
              <option value="<?php echo intval($t->id); ?>">
                <?php echo esc_html($t->name); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>

      <tr>
        <th>Activo</th>
        <td>
          <label>
            <input type="checkbox" name="is_active" value="1" checked>
            Disponible para goleador
          </label>
        </td>
      </tr>
    </table>

    <p>
      <button class="button button-primary" name="polla_add_player" value="1">
        Guardar jugador
      </button>
    </p>
  </form>

  <hr>

  <h2>Listado de jugadores</h2>

  <?php
  $players = $wpdb->get_results("
    SELECT p.id, p.name, p.is_active, t.name AS team_name
    FROM $players_table p
    LEFT JOIN $teams_table t ON t.id = p.team_id
    ORDER BY p.name ASC
    LIMIT 200
  ");

  if (!$players) {
    echo '<p>No hay jugadores registrados.</p>';
  } else {
    echo '<table class="widefat striped">';
    echo '<thead><tr>
            <th>Nombre</th>
            <th>Equipo</th>
            <th>Activo</th>
          </tr></thead><tbody>';

    foreach ($players as $p) {
      echo '<tr>';
      echo '<td>'.esc_html($p->name).'</td>';
      echo '<td>'.esc_html($p->team_name ?? '—').'</td>';
      echo '<td>'.($p->is_active ? 'Sí' : 'No').'</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
  }

  echo '</div>';
}

// Funciones auxiliares para especiales
private function polla_special_deadline_ts(): int {
  // 11 de junio 12:00 PM Colombia (America/Bogota)
  $tz = new DateTimeZone('America/Bogota');
  $dt = new DateTime('2026-06-11 12:00:00', $tz);
  return $dt->getTimestamp();
}

private function polla_special_is_open(): bool {
  $now = current_time('timestamp'); // WordPress time (según zona WP)
  // Para ser 100% consistente con Colombia, puedes usar wp_timezone() si la tienes en Bogotá
  // pero como pediste hora Colombia fija, usamos deadline absoluta:
  return $now < $this->polla_special_deadline_ts();
}

public function shortcode_home() {
  global $wpdb;

  $login_url  = wp_login_url(home_url('/'));
  $logout_url = wp_logout_url(home_url('/'));

  // Si NO está logueado
  if (!is_user_logged_in()) {
    $html  = '<div class="polla-home">';
    $html .= '  <h2>Polla Mundial MVP</h2>';
    $html .= '  <p>Para empezar, inicia sesión.</p>';
    $html .= '  <p><a class="polla-btn" href="'.esc_url($login_url).'">Iniciar sesión</a></p>';
    $html .= '</div>';
    return $html;
  }

  // ✅ Helper: obtener URL por TÍTULO de página (sin get_page_by_title)
  $get_url = function(string $title, string $fallback = '/') : string {
    $q = new WP_Query([
      'post_type'              => 'page',
      'post_status'            => 'publish',
      'title'                  => $title,
      'posts_per_page'         => 1,
      'no_found_rows'          => true,
      'ignore_sticky_posts'    => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'fields'                 => 'ids',
    ]);

    if (!empty($q->posts)) {
      return get_permalink((int)$q->posts[0]);
    }
    return home_url($fallback);
  };

  // ✅ URLs correctas según tus páginas (títulos exactos)
  $url_inicio        = $get_url('Inicio', '/');
  $url_pronosticos   = $get_url('Predicciones', '/predicciones/');
  $url_especiales    = $get_url('Especiales', '/especiales/');
  $url_ligas         = $get_url('Ligas', '/ligas/');
  $url_mis_ligas     = $get_url('Mis ligas', '/mis-ligas/');
  $url_ranking_glob  = $get_url('Ranking Global', '/ranking-global/');
  $url_ranking_ligas = $get_url('Ranking ligas', '/ranking-ligas/'); // 👈 exacto como dijiste

  $user_id   = get_current_user_id();
  $user      = wp_get_current_user();
  $user_name = $user->display_name;

  // 🔥 Traemos ranking global (igual al shortcode_leaderboard_global)
  $rows = $wpdb->get_results("
    SELECT 
      u.ID AS user_id,
      u.display_name AS user,
      COALESCE(SUM(s.points_total), 0) + COALESCE(MAX(sp.points_total), 0) AS total_points
    FROM {$wpdb->users} u
    LEFT JOIN {$wpdb->prefix}polla_predictions p ON p.user_id = u.ID
    LEFT JOIN {$wpdb->prefix}polla_prediction_scores s ON s.prediction_id = p.id
    LEFT JOIN {$wpdb->prefix}polla_special_scores sp ON sp.user_id = u.ID
    GROUP BY u.ID
    ORDER BY total_points DESC, u.display_name ASC
  ");

  // Buscar mi posición y puntos
  $my_pos = null;
  $my_pts = 0;

  if ($rows) {
    $pos = 1;
    foreach ($rows as $r) {
      if ((int)$r->user_id === (int)$user_id) {
        $my_pos = $pos;
        $my_pts = (int)$r->total_points;
        break;
      }
      $pos++;
    }
  }

  if ($my_pos === null) {
    $my_pos = '—';
    $my_pts = 0;
  }

  // ✅ UI Home
  $html  = '<div class="polla-home">';
  $html .= '  <h2 class="polla-title">Mundial 2026 ⚽ </h2>';
  $html .= '  <p class="polla-welcome">Hola, <strong>'.esc_html($user_name).'</strong></p>';

  // Mini indicadores
  $html .= '  <div class="polla-mini-stats">';
  $html .= '    <span class="polla-chip">Puesto <strong>#'.esc_html($my_pos).'</strong></span>';
  $html .= '    <span class="polla-chip">Puntos <strong>'.esc_html($my_pts).'</strong></span>';
  $html .= '  </div>';

  // Cards (dashboard)
  $html .= '  <div class="polla-grid">';

  // Pronósticos
  $html .= '    <a class="polla-card" href="'.esc_url($url_pronosticos).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">🔥</span>';
  $html .= '        <div class="polla-card__title"> Pronósticos</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Elige marcadores y suma puntos.</div>';
  $html .= '      <div class="polla-card__cta">Ir ahora →</div>';
  $html .= '    </a>';

  // Especiales
  $html .= '    <a class="polla-card" href="'.esc_url($url_especiales).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">🏆</span>';
  $html .= '        <div class="polla-card__title"> Especiales</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Campeón, podio y goleador.</div>';
  $html .= '      <div class="polla-card__cta">Jugar →</div>';
  $html .= '    </a>';

  // Ligas
  $html .= '    <a class="polla-card" href="'.esc_url($url_ligas).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">👥</span>';
  $html .= '        <div class="polla-card__title"> Ligas</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Crea o únete con un código.</div>';
  $html .= '      <div class="polla-card__cta">Ver ligas →</div>';
  $html .= '    </a>';

  // Mis ligas
  $html .= '    <a class="polla-card" href="'.esc_url($url_mis_ligas).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">📌</span>';
  $html .= '        <div class="polla-card__title"> Mis ligas</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Tus códigos, invitaciones y accesos.</div>';
  $html .= '      <div class="polla-card__cta">Abrir →</div>';
  $html .= '    </a>';

  // Ranking Global
  $html .= '    <a class="polla-card" href="'.esc_url($url_ranking_glob).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">🥇</span>';
  $html .= '        <div class="polla-card__title"> Ranking Global</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Revisa tu posición contra todos.</div>';
  $html .= '      <div class="polla-card__cta">Ver ranking →</div>';
  $html .= '    </a>';

  // Ranking ligas
  $html .= '    <a class="polla-card" href="'.esc_url($url_ranking_ligas).'">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">⚔️</span>';
  $html .= '        <div class="polla-card__title"> Ranking ligas</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Competencia directa con amigos.</div>';
  $html .= '      <div class="polla-card__cta">Abrir →</div>';
  $html .= '    </a>';

  $html .= '  </div>'; // grid
  $html .= '</div>';   // home

  return $html;
}

private function polla_get_user_total_points($user_id) {
  global $wpdb;

  $table = $wpdb->prefix . 'polla_leaderboard_global';

  $points = $wpdb->get_var(
    $wpdb->prepare(
      "SELECT total_points FROM {$table} WHERE user_id = %d",
      $user_id
    )
  );

  return $points ? (int)$points : 0;
}

private function polla_get_user_rank_global($user_id) {
  global $wpdb;

  $table = $wpdb->prefix . 'polla_leaderboard_global';

  $my_points = $wpdb->get_var(
    $wpdb->prepare(
      "SELECT total_points FROM {$table} WHERE user_id = %d",
      $user_id
    )
  );

  if ($my_points === null) return null;

  $rank = $wpdb->get_var(
    $wpdb->prepare(
      "SELECT 1 + COUNT(*) 
       FROM {$table}
       WHERE total_points > %d",
      $my_points
    )
  );

  return (int)$rank;
}


public function shortcode_leaderboard_liga() {
  if (!is_user_logged_in()) {
    $login_url = wp_login_url(get_permalink());
    return '<div class="polla-card"><p>Debes iniciar sesión para ver el ranking de una liga. <a class="polla-link" href="'.esc_url($login_url).'">Iniciar sesión</a></p></div>';
  }

  global $wpdb;
  $groups_table  = $wpdb->prefix . 'polla_groups';
  $members_table = $wpdb->prefix . 'polla_group_members';
  $pred_table    = $wpdb->prefix . 'polla_predictions';
  $score_table   = $wpdb->prefix . 'polla_prediction_scores';
  $match_table   = $wpdb->prefix . 'polla_matches';

  $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

  if (!$group_id && !empty($_GET['code'])) {
    $code = strtoupper(sanitize_text_field($_GET['code']));
    $group_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $groups_table WHERE join_code = %s",
      $code
    ));
  }

  // Si no mandan liga, mostramos "elige una de mis ligas"
  if (!$group_id) {
    $user_id = get_current_user_id();
    $my = $wpdb->get_results($wpdb->prepare("
      SELECT g.id, g.name, g.join_code
      FROM $members_table m
      JOIN $groups_table g ON g.id = m.group_id
      WHERE m.user_id = %d
      ORDER BY m.joined_at DESC
    ", $user_id));

    if (!$my) {
      return '<div class="polla-card"><p class="polla-muted">No estás en ninguna liga aún.</p></div>';
    }

    $out  = '<section class="polla-page">';
    $out .= '  <div class="polla-hero">';
    $out .= '    <h1 class="polla-title">Ranking por Liga ⚔️</h1>';
    $out .= '    <p class="polla-subtitle">Elige una liga para ver la tabla y la pelea interna.</p>';
    $out .= '  </div>';

    $out .= '  <div class="polla-card polla-card--glow">';
    $out .= '    <ul class="polla-list">';
    foreach ($my as $g) {
      $url = esc_url(add_query_arg(['group_id' => intval($g->id)], get_permalink()));
      $out .= '<li class="polla-list-item"><a class="polla-link" href="'.$url.'">'.esc_html($g->name).'</a> <span class="polla-chip">CODE '.esc_html($g->join_code).'</span></li>';
    }
    $out .= '    </ul>';
    $out .= '    <p class="polla-hint"><small>Tip: Puedes usar el código de la liga para que tus amigos se unan a ella.</small></p>';
    $out .= '  </div>';
    $out .= '</section>';

    return $out;
  }

  // Traer nombre de liga
  $group = $wpdb->get_row($wpdb->prepare("SELECT name, join_code FROM $groups_table WHERE id = %d", $group_id));
  if (!$group) return '<div class="polla-card"><p class="polla-muted">La liga no existe.</p></div>';

  // Validar que el usuario pertenece a la liga
  $user_id = get_current_user_id();
  $is_member = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT 1 FROM $members_table WHERE group_id = %d AND user_id = %d LIMIT 1",
    $group_id, $user_id
  ));
  if (!$is_member) {
    return '<div class="polla-card"><p class="polla-muted">No tienes acceso a esta liga.</p></div>';
  }

  // Ranking: todos los miembros, aunque tengan 0 puntos (LEFT JOIN)
  $rows = $wpdb->get_results($wpdb->prepare("
  SELECT 
    u.ID,
    u.display_name AS user,
    COALESCE(pm.match_points, 0) + COALESCE(sp.points_total, 0) AS total_points
  FROM $members_table gm
  JOIN {$wpdb->users} u ON u.ID = gm.user_id

  /* ✅ Puntos por partidos terminados (pre-agrupado) */
  LEFT JOIN (
    SELECT 
      p.user_id,
      SUM(s.points_total) AS match_points
    FROM $pred_table p
    JOIN $match_table m ON m.id = p.match_id
    JOIN $score_table s ON s.prediction_id = p.id
    WHERE m.status = 'finished'
    GROUP BY p.user_id
  ) pm ON pm.user_id = u.ID

  /* ✅ Puntos especiales (1 fila por usuario) */
  LEFT JOIN {$wpdb->prefix}polla_special_scores sp ON sp.user_id = u.ID

  WHERE gm.group_id = %d
  GROUP BY u.ID
  ORDER BY total_points DESC, u.display_name ASC
", $group_id));

  if (!$rows) return '<div class="polla-card"><p class="polla-muted">Esta liga no tiene miembros aún.</p></div>';

  $back = esc_url(remove_query_arg(['group_id','code'], get_permalink()));

  $html  = '<section class="polla-page">';
  $html .= '  <div class="polla-hero">';
  $html .= '    <h1 class="polla-title">Ranking: '.esc_html($group->name).'</h1>';
  $html .= '    <p class="polla-subtitle">Código: <span class="polla-chip">CODE '.esc_html($group->join_code).'</span></p>';
  $html .= '    <p><a class="polla-link" href="'.$back.'">← Volver a mis ligas</a></p>';
  $html .= '  </div>';

  $html .= '  <div class="polla-card polla-card--glow">';
  $html .= '    <div class="polla-table-wrap">';
  $html .= '      <table class="polla-table">';
  $html .= '        <thead><tr>';
  $html .= '          <th class="col-pos">#</th>';
  $html .= '          <th class="col-user">Usuario</th>';
  $html .= '          <th class="col-points">Puntos</th>';
  $html .= '        </tr></thead><tbody>';

  $pos = 1;
  foreach ($rows as $r) {
    $badge = '';
    if ($pos === 1) $badge = '<span class="polla-badge polla-badge--gold">🥇</span>';
    elseif ($pos === 2) $badge = '<span class="polla-badge polla-badge--silver">🥈</span>';
    elseif ($pos === 3) $badge = '<span class="polla-badge polla-badge--bronze">🥉</span>';

    $row_class = '';
    if ($pos === 1) $row_class = ' is-top is-top-1';
    elseif ($pos === 2) $row_class = ' is-top is-top-2';
    elseif ($pos === 3) $row_class = ' is-top is-top-3';

    $html .= '<tr class="polla-row'.$row_class.'">';
    $html .= '  <td class="col-pos"><span class="polla-pos">'.$pos.'</span></td>';
    $html .= '  <td class="col-user"><span class="polla-user">'.esc_html($r->user).'</span>'.$badge.'</td>';
    $html .= '  <td class="col-points"><span class="polla-points">'.intval($r->total_points).'</span></td>';
    $html .= '</tr>';
    $pos++;
  }

  $html .= '        </tbody></table>';
  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '</section>';

  return $html;
}

private function polla_generate_join_code($length = 6) {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $code = '';
  for ($i=0; $i<$length; $i++) {
    $code .= $chars[random_int(0, strlen($chars)-1)];
  }
  return $code;
}

public function handle_group_create() {
  if (!is_user_logged_in()) return;
  if (!isset($_POST['polla_create_group'])) return;

  $redirect = $this->polla_get_page_url_by_title('Ligas', '/ligas/');

  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'polla_create_group_action')) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Nonce inválido. Recarga e intenta de nuevo.', $redirect));
    exit;
  }

  global $wpdb;
  $groups_table  = $wpdb->prefix . 'polla_groups';
  $members_table = $wpdb->prefix . 'polla_group_members';

  $name = trim(sanitize_text_field($_POST['group_name'] ?? ''));
  if ($name === '') {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Nombre requerido', $redirect));
    exit;
  }

  $user_id = get_current_user_id();

  // Evitar duplicados (mismo owner y mismo nombre)
  $already = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $groups_table WHERE name = %s AND owner_user_id = %d",
    $name, $user_id
  ));
  if ($already) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Ya tienes una liga con ese nombre.', $redirect));
    exit;
  }

  // Generar join_code único
  $join_code = '';
  for ($i = 0; $i < 10; $i++) {
    $try = $this->polla_generate_join_code(6);
    $exists = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $groups_table WHERE join_code = %s",
      $try
    ));
    if (!$exists) { $join_code = $try; break; }
  }

  if (!$join_code) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'No se pudo generar un código único, intenta de nuevo.', $redirect));
    exit;
  }

  // ✅ Detectar columnas reales de la tabla (para evitar inserts que fallen)
  $cols = $wpdb->get_col("SHOW COLUMNS FROM $groups_table", 0);
  $has_code = in_array('code', $cols, true);

  $data = [
    'name'          => $name,
    'join_code'     => $join_code,
    'owner_user_id' => $user_id,
    'is_private'    => 0,
    'created_at'    => current_time('mysql'),
  ];
  $format = ['%s','%s','%d','%d','%s'];

  // ✅ Si existe la columna `code`, la llenamos también (misma que join_code)
  if ($has_code) {
    $data['code'] = $join_code;
    $format = ['%s','%s','%s','%d','%d','%s'];
  }

  $ok = $wpdb->insert($groups_table, $data, $format);

  if (!$ok) {
    // Si quieres ver el error exacto en pantalla (solo debug), descomenta:
    // wp_die('DB ERROR: ' . esc_html($wpdb->last_error));
    wp_safe_redirect(add_query_arg('polla_gerr', 'Error guardando liga en BD.', $redirect));
    exit;
  }

  $group_id = (int) $wpdb->insert_id;

  // Agregar owner como miembro
  $wpdb->insert($members_table, [
    'group_id'  => $group_id,
    'user_id'   => $user_id,
    'role'      => 'owner',
    'joined_at' => current_time('mysql'),
  ], ['%d','%d','%s','%s']);

  wp_safe_redirect(add_query_arg('polla_gok', '1', $redirect));
  exit;
}


public function handle_group_join() {
  if (!is_user_logged_in()) return;
  if (!isset($_POST['polla_join_group'])) return;

  // Redirect seguro (evita caer a home si no hay referer)
  $redirect = !empty($_POST['redirect_to'])
    ? esc_url_raw(wp_unslash($_POST['redirect_to']))
    : (wp_get_referer() ?: site_url('/ligas/'));

  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'polla_join_group_action')) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Nonce inválido. Recarga e intenta de nuevo.', $redirect));
    exit;
  }

  global $wpdb;
  $groups_table  = $wpdb->prefix . 'polla_groups';
  $members_table = $wpdb->prefix . 'polla_group_members';

  $code = strtoupper(sanitize_text_field($_POST['join_code'] ?? ''));
  $code = trim($code);

  if ($code === '') {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Código requerido', $redirect));
    exit;
  }

  $group_id = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $groups_table WHERE join_code = %s LIMIT 1",
    $code
  ));

  if (!$group_id) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'Código no válido', $redirect));
    exit;
  }

  $user_id = get_current_user_id();

  // evitar duplicados
  $already = (int)$wpdb->get_var($wpdb->prepare(
     "SELECT 1 FROM $members_table WHERE group_id = %d AND user_id = %d LIMIT 1",
     $group_id, $user_id
  ));

  if ($already) {
    wp_safe_redirect(add_query_arg('polla_already', '1', $redirect));
    exit;
  }

  $ok = $wpdb->insert($members_table, [
    'group_id' => $group_id,
    'user_id'  => $user_id,
    'role'     => 'member',
    'joined_at'=> current_time('mysql'),
  ], ['%d','%d','%s','%s']);

  if (!$ok) {
    wp_safe_redirect(add_query_arg('polla_gerr', 'No se pudo unir a la liga (BD).', $redirect));
    exit;
  }

  wp_safe_redirect(add_query_arg('polla_joined', '1', $redirect));
  exit;
}


public function shortcode_grupos() {
  // Si viene link de invitación ?join=XXXX
  $invite_code = '';
  if (!empty($_GET['join'])) {
    $invite_code = strtoupper(sanitize_text_field($_GET['join']));
  }

  // URL actual para volver sí o sí (evita caer a home cuando referer viene vacío)
  $current_url = home_url(add_query_arg([], wp_unslash($_SERVER['REQUEST_URI'])));

  // Si no está logueado y venía con invite, mandarlo a login y que vuelva aquí
  if (!is_user_logged_in()) {
    $login_url = wp_login_url(get_permalink());
    if ($invite_code) {
      $login_url = wp_login_url(add_query_arg('join', $invite_code, get_permalink()));
    }
    return '<div class="polla-card"><p>Debes iniciar sesión para crear/unirte a ligas. <a class="polla-link" href="'.esc_url($login_url).'">Iniciar sesión</a></p></div>';
  }

  // Mensajes
  $msg = '';
  if (!empty($_GET['polla_gok'])) {
    $msg .= '<div class="polla-card" style="border-left:4px solid #2e7d32;">✅ Liga creada.</div>';
  }
  if (!empty($_GET['polla_joined'])) {
    $msg .= '<div class="polla-card" style="border-left:4px solid #2e7d32;">✅ Te uniste a la liga.</div>';
  }
  if (!empty($_GET['polla_already'])) {
    $msg .= '<div class="polla-card" style="border-left:4px solid #dba617;">⚠️ Ya perteneces a esa liga.</div>';
  }
  if (!empty($_GET['polla_gerr'])) {
    $msg .= '<div class="polla-card" style="border-left:4px solid #b71c1c;">❌ '.esc_html(sanitize_text_field($_GET['polla_gerr'])).'</div>';
  }

  $html  = '<section class="polla-page">';
  $html .= '  <div class="polla-hero">';
  $html .= '    <h1 class="polla-title">Ligas 👥</h1>';
  $html .= '    <p class="polla-subtitle">Crea tu mini liga o únete con un código para competir con tus amigos.</p>';
  $html .= '  </div>';

  $html .= $msg;

  // ✅ Invitación detectada
  if ($invite_code) {
    $html .= '  <div class="polla-card polla-card--glow" style="margin-bottom:14px;">';
    $html .= '    <div class="polla-card__top">';
    $html .= '      <span class="polla-badge">🎟️</span>';
    $html .= '      <div class="polla-card__title">Invitación detectada</div>';
    $html .= '    </div>';
    $html .= '    <div class="polla-card__desc">Código: <span class="polla-chip">JOIN '.esc_html($invite_code).'</span></div>';
    $html .= '    <form method="post" action="'.esc_url($current_url).'" style="margin-top:12px;">';
    $html .=          wp_nonce_field('polla_join_group_action', '_wpnonce', true, false);
    $html .= '      <input type="hidden" name="redirect_to" value="'.esc_attr($current_url).'">';
    $html .= '      <input type="hidden" name="join_code" value="'.esc_attr($invite_code).'">';
    $html .= '      <button class="polla-btn" type="submit" name="polla_join_group" value="1">Unirme a esta liga →</button>';
    $html .= '    </form>';
    $html .= '  </div>';
  }

  // ✅ Grid: Crear / Unirse
  $html .= '  <div class="polla-form-grid">';

  // CREAR
  $html .= '    <div class="polla-card polla-card--glow">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">👥</span>';
  $html .= '        <div class="polla-card__title">Crear mini liga</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Ponle nombre y genera tu código de invitación.</div>';
  $html .= '      <form method="post" action="'.esc_url($current_url).'" style="margin-top:12px;">';
  $html .=            wp_nonce_field('polla_create_group_action', '_wpnonce', true, false);
  $html .= '        <input type="hidden" name="redirect_to" value="'.esc_attr($current_url).'">';
  $html .= '        <div class="polla-inline">';
  $html .= '          <input class="polla-input" type="text" name="group_name" placeholder="Ej: Los cracks 🔥" required>';
  $html .= '          <button class="polla-btn" type="submit" name="polla_create_group" value="1">Crear</button>';
  $html .= '        </div>';
  $html .= '      </form>';
  $html .= '    </div>';

  // UNIRSE
  $html .= '    <div class="polla-card polla-card--glow">';
  $html .= '      <div class="polla-card__top">';
  $html .= '        <span class="polla-badge">🔑</span>';
  $html .= '        <div class="polla-card__title">Unirse por código</div>';
  $html .= '      </div>';
  $html .= '      <div class="polla-card__desc">Pega el código y entra a la pelea.</div>';
  $html .= '      <form method="post" action="'.esc_url($current_url).'" style="margin-top:12px;">';
  $html .=            wp_nonce_field('polla_join_group_action', '_wpnonce', true, false);
  $html .= '        <input type="hidden" name="redirect_to" value="'.esc_attr($current_url).'">';
  $html .= '        <div class="polla-inline">';
  $html .= '          <input class="polla-input" type="text" name="join_code" placeholder="Ej: A7K9Q2" maxlength="10" required style="text-transform:uppercase;">';
  $html .= '          <button class="polla-btn" type="submit" name="polla_join_group" value="1">Unirme</button>';
  $html .= '        </div>';
  $html .= '      </form>';
  $html .= '    </div>';

  $html .= '  </div>'; // form-grid

  $html .= '  <p class="polla-help">Esto <strong>NO</strong> afecta tu ranking global. Es un extra para competir con amigos.</p>';
  $html .= '</section>';

  return $html;
}


public function shortcode_mis_ligas() {
  if (!is_user_logged_in()) {
    $login_url = wp_login_url(get_permalink());
    return '<p>Debes iniciar sesión para ver tus ligas. <a href="'.esc_url($login_url).'">Iniciar sesión</a></p>';
  }

  global $wpdb;
  $groups_table  = $wpdb->prefix . 'polla_groups';
  $members_table = $wpdb->prefix . 'polla_group_members';

  $user_id = get_current_user_id();

  $rows = $wpdb->get_results(
    $wpdb->prepare("
      SELECT 
        g.id, g.name, g.join_code, g.owner_user_id, g.created_at,
        m.role, m.joined_at
      FROM $members_table m
      JOIN $groups_table g ON g.id = m.group_id
      WHERE m.user_id = %d
      ORDER BY m.joined_at DESC
    ", $user_id)
  );

  if (!$rows) {
    return '<p>Aún no estás en ninguna liga. Ve a <strong>[polla_grupos]</strong> para crear o unirte por código.</p>';
  }

  $html  = '<h3>Mis ligas</h3>';
  $html .= '<table style="width:100%; border-collapse:collapse;">';
  $html .= '<thead><tr>
      <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd;">Liga</th>
      <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd;">Mi rol</th>
      <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd;">Código</th>
      <th style="text-align:left; padding:10px; border-bottom:1px solid #ddd;">Desde</th>
    </tr></thead><tbody>';

  foreach ($rows as $r) {
    $is_owner = ((int)$r->owner_user_id === $user_id);

    $code   = $is_owner ? esc_html($r->join_code) : '—';
    $invite = $is_owner ? esc_url(site_url('/ligas/?join=' . rawurlencode($r->join_code))) : '';
    $rank_url = esc_url(site_url('/ranking-liga/?group_id=' . intval($r->id)));


    $html .= '<tr>';
    $html .= '<td style="padding:10px; border-bottom:1px solid #eee;">
    <a href="'.$rank_url.'" style="text-decoration:none; font-weight:600;">'.esc_html($r->name).'</a>
    </td>';


    $html .= '<td style="padding:10px; border-bottom:1px solid #eee;">'.esc_html($r->role).'</td>';

    $html .= '<td style="padding:10px; border-bottom:1px solid #eee;">';

    if ($is_owner) {
    $html .= '<code>'.$code.'</code>';

    $html .= '<br><small>'
        . '<a href="'.$invite.'" target="_blank" rel="noopener">Link de invitación</a>'
        . ' · '
        . '<a href="'.$rank_url.'">Ver ranking</a>'
        . '</small>';
}  else {
  // Si NO es owner, puedes igual mostrar "Ver ranking" (sí tiene acceso por ser miembro)
    $html .= '<small><a href="'.$rank_url.'">Ver ranking</a></small>';
}

  $html .= '</td>';


    $html .= '<td style="padding:10px; border-bottom:1px solid #eee;">'.esc_html($r->joined_at).'</td>';
    $html .= '</tr>';
  }

  $html .= '</tbody></table>';

  return $html;
}
//Redirigir en liga
private function polla_get_page_url_by_title(string $title, string $fallback = '/'): string {
  $q = new WP_Query([
    'post_type'              => 'page',
    'post_status'            => 'publish',
    'title'                  => $title,
    'posts_per_page'         => 1,
    'no_found_rows'          => true,
    'ignore_sticky_posts'    => true,
    'update_post_meta_cache' => false,
    'update_post_term_cache' => false,
    'fields'                 => 'ids',
  ]);

  if (!empty($q->posts)) return get_permalink((int)$q->posts[0]);
  return home_url($fallback);
}
}

new PollaMundialMVP();
