<?php
/*
Plugin Name: TMDB Film & Serien Liste
Description: Zeigt Filme oder Serien von TMDB mit Pagination.
Version: 1.0
Author: Kristian Knorr
*/

// Direktzugriff verhindern
if (!defined('ABSPATH')) {
    exit;
}

define( 'TMDB_RESSOURCES_DIR', plugin_dir_url( __FILE__ ) . 'assets/');

// Hinzufügen der Menü-Seite für Plugin-Einstellungen
function tmdb_add_settings_page() {
    add_options_page(
        'TMDB Einstellungen',
        'TMDB',
        'manage_options',
        'tmdb-settings',
        'tmdb_render_settings_page'
    );
}
add_action('admin_menu', 'tmdb_add_settings_page');

// Rendering der Einstellungsseite
function tmdb_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>TMDB Einstellungen</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('tmdb_settings_group');
            do_settings_sections('tmdb-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Registrierung der Einstellungen
function tmdb_register_settings() {
    register_setting('tmdb_settings_group', 'tmdb_api_key');

    add_settings_section(
        'tmdb_settings_section',
        'API-Einstellungen',
        null,
        'tmdb-settings'
    );

    add_settings_field(
        'tmdb_api_key',
        'TMDB API Key',
        'tmdb_api_key_field_callback',
        'tmdb-settings',
        'tmdb_settings_section'
    );
}
add_action('admin_init', 'tmdb_register_settings');

// Callback für das Eingabefeld
function tmdb_api_key_field_callback() {
    $api_key = get_option('tmdb_api_key', '');
    echo '<input type="text" id="tmdb_api_key" name="tmdb_api_key" value="' . esc_attr($api_key) . '" style="width: 400px;">';
}

// Verwendung des gespeicherten API-Schlüssels
function tmdb_get_api_key() {
    return get_option('tmdb_api_key', '');
}

// Genres aus der lokalen Datei laden
function tmdb_get_genres_from_file() {
    $file_path = __DIR__ . '/genres.json'; // Pfad zur genres.json
    
    if (!file_exists($file_path)) {
        return []; // Wenn die Datei nicht existiert, leere Liste zurückgeben
    }

    $data = file_get_contents($file_path);
    $genres = json_decode($data, true);

    // Optional: Typen (movie/tv) differenzieren, falls notwendig
    return isset($genres['genres']) ? $genres['genres'] : [];
}

// Shortcode zur Anzeige der Filme oder Serien
function tmdb_display_content($atts) {
    global $wp;
    
    $page_id = get_the_ID();
    $type = get_query_var('type') !== '' ? get_query_var('type') : 'movie'; // Standardtyp ist Film
    $page = intval(get_query_var('current_page')) > 0 ? intval(get_query_var('current_page')) : 1; // Standardseite ist 1
    $prev_page = $page - 1;
    $next_page = $page + 1;

    $api_key = tmdb_get_api_key(); // Hier den API-Key aus der Config holen

    // Validierung des Typs
    if (!in_array($type, ['movie', 'tv'])) {
        return 'Ungültiger Typ. Verwenden Sie "movie" oder "tv".';
    }

    // API URL erstellen
    $api_url = "https://api.themoviedb.org/3/discover/{$type}?language=de-DE&page={$page}";

    if($api_key === '' || strlen($api_key) < 200){
        return 'Kein Api Key in den Plugin Settings oder Api Key ungültig';
    }

    // API-Anfrage durchführen
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key
        ]
    ]);

    // Fehlerbehandlung
    if (is_wp_error($response)) {
        return 'Fehler bei der Verbindung zur API.';
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['results'])) {
        return 'Keine Ergebnisse gefunden.';
    }

    $page_count = $data['total_pages'];
    $movie_active = $type === 'movie' ? 'active' : '';
    $tv_active = $type === 'tv' ? 'active' : '';

    // Genres aus der Datei laden und zuordnen
    $genres = tmdb_get_genres_from_file();
   
    $genre_map = [];
    foreach ($genres as $genre) {
        $genre_map[$genre['id']] = $genre['name'];
    }

     // Genres in Items integrieren
     foreach ($data['results'] as &$item) {
        $item['genres'] = array_map(function ($genre_id) use ($genre_map) {
            return $genre_map[$genre_id] ?? 'Unbekannt';
        }, $item['genre_ids']);
    }

    $output = '
    <link href="'.TMDB_RESSOURCES_DIR.'fontawesome/css/fontawesome.css" rel="stylesheet" />
    <link href="'.TMDB_RESSOURCES_DIR.'fontawesome/css/brands.css" rel="stylesheet" />
    <link href="'.TMDB_RESSOURCES_DIR.'fontawesome/css/solid.css" rel="stylesheet" />';

    $output .= "
    <navigation>
        <div class='tmdb-navigation'>
            <a href='?page_id={$page_id}&type=movie&current_page={$current_page}'>
                <button class='nav-button {$movie_active}' data-type='movie'>Filme</button>
            </a>
            <a href='?page_id={$page_id}&type=tv&current_page={$current_page}'>
                <button class='nav-button {$tv_active}' data-type='tv'>TV</button>
            </a>
        </div>
    </navigation>";
   


    // Ausgabe der Ergebnisse
    $output .= '<div class="tmdb-list">';
    foreach ($data['results'] as $item) {
        $title = $item['title'] ?? $item['name'];
        $poster = "https://image.tmdb.org/t/p/w500" . $item['poster_path'];
        $popularity = $item['popularity'];
        $popularity_avg = round($item['vote_average'],1);
        $release_date = $item['release_date'] ?? $item['first_air_date'];
        $release_date = DateTime::createFromFormat('Y-m-d', $release_date)->format('d.m.Y');
        $overview = $item['overview'];
        $genres = implode(', ', $item['genres']);

        $output .= '<div class="tmdb-item">';
        $output .= "<div class='tmdb-poster-container'><img src='{$poster}' alt='{$title}' class='tmdb-poster'></div>";
        $output .= "<div class='tmdb-info'>";
        $output .= "<h3 class='tmdb-title'>{$title}</h3>";
        $output .= "<p class='tmdb-release-date'>Veröffentlichungsdatum: {$release_date}</p>";
        $output .= "<p class='tmdb-genres'><strong>Genres:</strong> {$genres}</p>";
        $output .= "<p class='tmdb-popularity'>Beliebtheit: {$popularity}</p>";
        $output .= "<p class='tmdb-avg'>Bewertung: {$popularity_avg}/10</p>";
        $output .= "<p class='tmdb-overview'>{$overview}</p>";
        $output .= "<p class='tmdb-actions'>
        <i class='fa-solid fa-star'></i><a href='#'>Bewerten</a>
        <i class='fa-solid fa-heart'></i><a href='#'>Favorit</a>
        <i class='fa-solid fa-rectangle-list'></i><a href='#'>Zur Liste hinzufügen</a>
        </p>";
        $output .= '</div>';
        $output .= '</div>';
    }
    $output .= '</div>';

    // Pagination hinzufügen
    $output .= '<div class="tmdb-pagination">';
    $output .= "<span class='tmdb-page-info'>Seite {$page} von {$page_count}</span>";
    if ($page > 1) {
        $output .= "<a href='?page_id={$page_id}&type={$type}&current_page={$prev_page}' class='tmdb-prev'>« Zurück</a>";
    }
    if ($page < $page_count) {
        $output .= "<a href='?page_id={$page_id}&type={$type}&current_page={$next_page}' class='tmdb-next'>Weiter »</a>";
    }
    $output .= '</div>';

    return $output;
}
add_shortcode('tmdb_list', 'tmdb_display_content');

// Styles hinzufügen
function tmdb_enqueue_styles() {
    wp_enqueue_style('tmdb-styles', TMDB_RESSOURCES_DIR . 'css/styles.css');
}
add_action('wp_enqueue_scripts', 'tmdb_enqueue_styles');

// Custom Query Vars hinzufügen
function themeslug_query_vars( $qvars ) {
    $qvars[] = 'current_page';
    $qvars[] = 'type';
    return $qvars;
    }
add_filter( 'query_vars', 'themeslug_query_vars' );

