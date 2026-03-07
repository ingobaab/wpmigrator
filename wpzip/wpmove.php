
<?php
/*
  Plugin Name: WPMove
  Plugin URI: https://wpbackup.org/wpmove
  Description: WPMove can archieve and transfer your wordpress site with a single click to the webhoster wpbackup.org/wpexpress.de
  Version: 1.6.4
  Author: Ingo Baab
  Author URI: https://wpbackup.org/
  Text Domain: wpmove
  Requieres at least: 4.0
  Tested up to: 6.1
  Contributors: wpexpress
  License:     GPLv2.0 or later
  License URI: https://www.gnu.org/licenses/gpl-2.0.html

  *
  * ██╗     ██╗██████╗ ███████╗ ███╗ ███╗ ██████╗ ██████╗ ███████╗███████╗███████╗
  * ██║     ██║██╔══██╗██╔════╝ ╚██████╔╝ ██╔══██╗██╔══██╗██╔════╝██╔════╝██╔════╝
  * ██║████╗██║██████╔╝█████╗    ╚████╔╝  ██████╔╝██████╔╝█████╗  ███████╗███████╗
  * ████╔═████║██╔═══╝ ██╔══╝    ██████╗  ██╔═══╝ ██╔══██╗██╔══╝  ╚════██║╚════██║
  * ███╔╝ ╚███║██║     ███████╗ ███╔═███╗ ██║     ██║  ██║███████╗███████║███████║
  * ╚══╝   ╚══╝╚═╝     ╚══════╝ ╚═╝ ╚═══╝ ╚═╝     ╚═╝  ╚═╝╚══════╝╚══════╝╚══════╝
  */

$time_start_0 = microtime(true);

$verb = isset($_REQUEST['verb'])?$_REQUEST['verb']:0;
        // 1: show guessed filesizes
        // 2: show details of generated local files
        // 3: show a lot more details in front-end
        // 4: developer debug, this will break GUI/js!

include(__DIR__.'/helper.inc.php');

add_action( 'wp_ajax_get_stats', 'get_stats' );
function get_stats() {
  global $db_stats, $totaldump, $dt1, $time_start_0, $totalSize, $dt2, $wp_dir, $arch_filename, $options, $compression, $wpconfig;
  #require_once( dirname( __FILE__ ) . '/../../../wp-load.php' );
  #check_ajax_referer('get_stats_nonce');

  $dbc = getDbConfig($wpconfig);
  $db_stats = mysql_get_stats($dbc['host'], $dbc['user'], $dbc['pass'], $dbc['name']); // fills: $db_stats['size']; $db_stats['rows']; $db_stats['tables'];

  $totaldump = $db_stats['dump_filesize_guess'] = (int)($db_stats['size']*(0.375)); // guess filesize is ~35%-40% of DB size
  $dt1 = microtime(1) - $time_start_0;

  $time_1 = microtime(1);
  calculateTotals($wp_dir);
  $dt2 = microtime(1) - $time_1;
  echo print_r($dbc,1) . "\n";


// Example translation functions to test translations
// echo __('verfügbar', 'wpzip');
// echo __('- nicht verfügbar -', 'wpzip');
$__Yes = __('Yes', 'wpzip');
$__No  = __('No', 'wpzip');
$__available = __('available', 'wpzip');
$__not_available = __('not available', 'wpzip');

  echo sprintf(sigma().' dump: ~            '.b('%.2f MB').' (%d %.2fs)', $totaldump/(1024*1024), $totaldump, $dt1) . '<br>';
  echo sprintf(sigma().' arch: ~            '.b('%.2f MB').' (%d %.2fs)', $totalSize/(1024*1024), $totalSize, $dt2) . '<br>';
  echo 'admin email:         ' . b(get_bloginfo('admin_email')) . '<br/>';
  echo 'temp_dir:            ' . b($GLOBALS['temp_dir']) . '<br/>';
  echo 'progress_dump_fn:    ' . b($GLOBALS['progress_dump_fn']) .'<br/>';
  echo 'progress_pack_fn:    ' . b($GLOBALS['progress_pack_fn']) .'<br/>';
  $doc_root = $_SERVER['DOCUMENT_ROOT'];
  echo 'arch_filename:       ' . b($arch_filename)             . '<br>';
  echo 'shell_exec available ' . b(is_shell_exec_enabled()   ? $__Yes : $__No) . '<br>';
  echo 'command mysqldump    ' . b(path_of_cmd('mysqldump'))   . '<br>';
  echo 'command mysqlimport  ' . b(path_of_cmd('mysqlimport')) . '<br>';
  echo 'command du           ' . b(path_of_cmd('du'))          . '<br>';
  echo 'command zip          ' . b(path_of_cmd('zip'))         . '<br>';
  echo 'command bzip2        ' . b(path_of_cmd('bzip2'))       . '<br>';
  echo 'command tar          ' . b(path_of_cmd('tar'))         . '<br>';
  echo 'PDO MySQL ist        ' . b(is_pdo_mysql_available()    ? $__available : $__not_available ) . '<br>';

  $wp_cmd = path_of_cmd('wp');
  echo 'wp command:          ' . b($wp_cmd) . ' ' . shell_exec($wp_cmd.' --version') . '<br>';
  $wp_cli_cmd = path_of_cmd('wp-cli.phar');
  echo 'wp-cli.phar command: ' . b($wp_cli_cmd) . ' ' . shell_exec($wp_cli_cmd.' --version') . '<br>';

  wp_die();
}

if(isset($_REQUEST['ajax'])) {
    if ($_REQUEST['ajax'] === 0) { // http://localhost:8000/wp-admin/admin.php?page=wpmove&ajax=0  https://www.youtube.com/watch?v=6sYHnx8LRNI
      phpinfo(); wp_die();
    }
    if ($_REQUEST['ajax'] === 1) { // http://localhost:8000/wp-admin/admin.php?page=wpmove&ajax=1  https://www.youtube.com/watch?v=Vshg-hNUEjo
      echo '<pre>'; get_stats(); wp_die();
    }
    if ($_REQUEST['ajax'] === 2) { // http://localhost:8000/wp-admin/admin.php?page=wpmove&ajax=2
      echo '<pre>'; my_arch_results(); wp_die();
    }
    if ($_REQUEST['ajax'] === 3) { // http://localhost:8000/wp-admin/admin.php?page=wpmove&ajax=3
      echo '<pre>'; my_dump_action(); wp_die();
    }
    if ($_REQUEST['ajax'] === 4) { // http://localhost:8000/wp-admin/admin.php?page=wpmove&ajax=4
      echo '<pre>'; my_pack_action(); wp_die();
    }
}

// Prüfen, ob wir uns auf der eigenen Plugin-Seite befinden
add_action('current_screen', function($screen) {
  global $nonce;

  if ($screen->id === 'toplevel_page_wpmove') {
      // Alle Notices entfernen
      remove_all_actions('admin_notices');
      remove_all_actions('all_admin_notices');
      $nonce = wp_create_nonce('get_stats_nonce');
  } else {
      return;
  }
});

#if (!is_admin() || !isset($_REQUEST['page']) || $_REQUEST['page']!="wpmove") return;

/*
function show_current_screen_id_in_footer() {
  if (is_admin()) {
      $screen = get_current_screen();
      if ($screen) {
          echo '<div style="padding: 10px; background: #f1f1f1; border-top: 1px solid #ccc; font-weight: bold;">Aktueller Screen ID: ' . esc_html($screen->id) . '</div>';
      }
  }
}
add_action('admin_footer', 'show_current_screen_id_in_footer');
*/

add_action( 'wp_ajax_my_arch_results', 'my_arch_results' );
function my_arch_results() {
  global $json_result_file;
  #if (file_exists($progress_dump_fn)) unlink($progress_dump_fn);
  #if (file_exists($progress_pack_fn)) unlink($progress_pack_fn);
  header('Content-Type: application/json');
  if(!file_exists($json_result_file)) echo json_encode(['error' => 'file "'.$json_result_file.'" was not found.']);
  else {
    echo trim(file_get_contents($json_result_file));
        // { "filename_arch_created":"\/wp-content\/uploads\/wpmove\/wpmove.bz2"
        //  ,"filesize_arch_created":69899817
        //  ,"bytestotal":197876161
        //  ,"nbfiles":11085
        //  ,"needed_time":142.5
        //  ,"compr_text":"bz2"
        //  ,"php_mem_peak":13139968
        //  ,"tiles_html":"<div>.."
        // }
  }
  echo ob_get_clean();
  wp_die();
}

add_action( 'wp_ajax_my_dump_action', 'my_dump_action' );
function my_dump_action() {
  global $progress_dump_fn, $progress_pack_fn, $options;
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  if (file_exists($progress_dump_fn)) unlink($progress_dump_fn);
  if (file_exists($progress_pack_fn)) unlink($progress_pack_fn);
  ob_start();

  $options['j']=true; // write progress to json file ($progress_dump_fn)
  #set_wpack_compression();

  mysql_dump();
  echo ob_get_clean();
  wp_die();
}

add_action( 'wp_ajax_my_pack_action', 'my_pack_action' );
function my_pack_action() {
  global $progress_pack_fn, $wp_dir, $arch_filename, $options;
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  if (file_exists($progress_pack_fn)) unlink($progress_pack_fn);
  ob_start();
  #include __DIR__.'/wpmove_pack.php';
  #system('wpack -j -c0');
  $options['j']=true;
  $options['c']=0;    // no compression;
  set_wpack_compression(); // modifies also $arch_filename (.wparch or .gz or .bz2)
  $result=ReadFolderDirectory($wp_dir, $arch_filename);   # do the real workload
  echo json_encode($result);
  create_wpack_json_results_from_arch_filename();
  echo ob_get_clean();
  wp_die();
}

add_action( 'wp_ajax_my_progress_dump_action', 'my_progress_dump_action' );
function my_progress_dump_action() {
  global $progress_dump_fn;
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  ob_start();
  header('Content-Type: application/json');
  $last_line=read_last_non_empty_line( $progress_dump_fn );
  if(empty($last_line)) {
    echo json_encode(['error'=>'Could not get a progress value!'.date('r')]);
  } else {
    echo $last_line;
  }
  echo ob_get_clean();
  wp_die();
}

add_action( 'wp_ajax_my_progress_pack_action', 'my_progress_pack_action' );
function my_progress_pack_action() {
  global $progress_pack_fn;
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  ob_start();
  header('Content-Type: application/json');
  echo read_last_non_empty_line( $progress_pack_fn );
  echo ob_get_clean();
  wp_die();
}


// if(!is_dir($upload_dir_abs)) {
//   mkdir($upload_dir_abs);
//   if(!is_file($upload_dir_abs.'/index.php')) {
//     file_put_contents($upload_dir_abs.'/index.php', "<?php\n// Silence is golden.\n");
//   }
// }

/*
try {
  if (!is_dir($upload_dir_abs)) {
      if (!@mkdir($upload_dir_abs, 0755, true)) {
          throw new RuntimeException("Directory could not be created: $upload_dir_abs");
      }
  }

  $index_file = $upload_dir_abs . '/index.php';
  if (!is_file($index_file)) {
      if (file_put_contents($index_file, "<?php\n// Silence is golden.\n") === false) {
          throw new RuntimeException("Could not create index.php in: $upload_dir_abs");
      }
  }
} catch (Exception $e) {
  // Log or handle the error as needed
  $wpmove_error = 'Error: ' . $e->getMessage();
  #echo wpmove_msg($wpmove_error);
}
*/

function wpmove_msg($str) {
  $html = '<div class="error">';
  $html .= '<p>';
  $html .= $str;
  $html .= '</p>';
  $html .= '</div>';
  return $html;
}

function wpmove_add_admin_menu_separator( $position ) {
  global $menu;
  $menu[ $position ] = array(
    0  =>  '',
    1  =>  'read',
    2  =>  'separator' . $position,
    3  =>  '',
    4  =>  'wp-menu-separator'
  );
}

add_action( 'admin_menu', 'wpmove_add_admin_menu_page', 9999 );
function wpmove_add_admin_menu_page() {
  add_menu_page( 'WPmove - Migrate WordPress Easy', 'WPmove', 'manage_options', 'wpmove', 'wpmove_function', '' );
}

if(0) { // activation_message_once
  /* Register activation hook. */
  register_activation_hook( __FILE__, 'wp_move_notice_install' );
  /**
   * Runs only when the plugin is activated.
   * @since 0.1.0
   */
  function wp_move_notice_install() {
      /* Create transient data */
      set_transient( 'wpmove-admin-notice', true, 0 );
  }

  /* Add admin notice */
  add_action( 'admin_notices', 'wpmove_admin_notice' );

  /**
   * Admin Notice on Activation.
   * @since 0.1.0
   */
  function wpmove_admin_notice(){
      /* Check transient, if available: display notice */
      if( get_transient( 'wpmove-admin-notice' ) ){
          ?>
          <div class="updated notice is-dismissible">
              <p>Thank you for using this plugin! <strong>You are awesome</strong>.</p>
          </div>
          <?php
          /* Delete transient, only display this notice once. */
          delete_transient( 'wpmove-admin-notice' );
      }
  }
} else { // redirect if only our (one) plugin is activated, don't redirect if multiple plugins are activated

  register_activation_hook(__FILE__, 'wpmove_plugin_activate');
  add_action('admin_init', 'wpmove_plugin_redirect');

  function wpmove_plugin_activate() {
    add_option('wpmove_plugin_do_activation_redirect', true);
  }

  function wpmove_plugin_redirect() {
    if (get_option('wpmove_plugin_do_activation_redirect', false)) {
      delete_option('wpmove_plugin_do_activation_redirect');
      if(!isset($_GET['activate-multi'])) {
          wp_redirect("admin.php?page=wpmove");
      }
    }
  }
}

#if (file_exists($progress_dump_fn)) unlink($progress_dump_fn);
#if (file_exists($progress_pack_fn)) unlink($progress_pack_fn);


function wpmove_function() {

  global $time_start_0, $verb, $nonce, $totalarch, $totaldump, $DirSizeGuess, $db_stats, $dt1, $dt2, $dt3, $wp_dir, $wpmove_error;

  $wpe_domain = substr($_SERVER['HTTP_HOST'], 0, strrpos($_SERVER['HTTP_HOST'], '.')) . '.wpbackup.org';

  if ( !empty($wpmove_error)) { echo '<div class="error">Fehler: ' . $wpmove_error . '</div>'; }

  ?>

  <div id="wpbody" role="main" style="max-width: 1120px;">
    <div id="wpbody-content">
      <div class="wrap">
        <h1 class="wp-heading-inline">Create a simple and easy to use self-extracting WordPress Archive</h1>
        <hr class="wp-header-end">

        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper wp-clearfix">
          <a href="#tab-main" class="nav-tab nav-tab-active">Main</a>
          <a href="#tab-images" class="nav-tab">Images</a>
          <a href="#tab-advanced" class="nav-tab">Advanced</a>
          <a href="#tab-about" class="nav-tab">About</a>
        </nav>

        <!-- Tab Contents -->
        <div class="tab-content">
          <!-- Main Tab -->
          <div id="tab-main" class="tab-pane active">
            <form id="wpmove_form">
              <div id="progress_bars">
                <div id="progress-bars">
                  <h5>Creating your MySQL-Database-Dump:</h5>
                  <div class="progress-container">
                    <div class="progress-bar" id="progressBar-1"></div>
                    <div class="progress-text" id="progressText-1">0%</div>
                  </div>
                  <div class="extra-text" id="extraText-1">Initializing...</div>

                  <div class="progress-container">
                    <div class="progress-bar" id="progressBar-2"></div>
                    <div class="progress-text" id="progressText-2">0%</div>
                  </div>
                  <div class="extra-text" id="extraText-2">Initializing-2...</div>


                  <input id="dump_again" class="button" value="Dump again">
                  <script>
                    jQuery("#dump_again").on("click", function() {
                      console.log('dump_again was pressed!');
                      startDump();
                    });
                    </script>
                  <input id="pack_again" class="button" value="Pack again">
                  <input id="resu_again" class="button" value="Results again">
                  <input id="stop_timers" class="button" value="Stop all timers">

                </div>

                <div id="results">
                  <div id="spinner-container">
                    <img id="wp-spinner" src="<?php echo admin_url('images/spinner.gif'); ?>" alt="Please wait, checking this WordPress environment...">
                    <div>Please wait, checking this WordPress environment...</div>
                  </div>
                </div>


<input type="checkbox" id="yes_i_agree">
<label for="yes_i_agree">Yes, please migrate my site.</label>

<p class="submit">
  <input id="publish_button" name="save" type="button" class="button button-primary" value="Backup My WordPress" style="display:none;">
</p>

<script>
  jQuery("#yes_i_agree").on("change", function() {
    if (jQuery(this).is(":checked")) {
      jQuery("#publish_button").slideDown(300);
    } else {
      jQuery("#publish_button").slideUp(0);
    }
  });
</script>

              </div>
            </form>
          </div>

          <!-- Images Tab -->
          <div id="tab-images" class="tab-pane">
            <h2>Plugin Images</h2>
            <div class="wpmove-images">
              <?php
              $images = ['wpzip-logo-ai.png', 'wpzip-logo.png', 'wpzip-logo.svg'];
              foreach ($images as $image) {
                echo '<img src="' . plugin_dir_url(__FILE__) . 'img/' . $image . '" alt="WPZip Logo" width="300" style="margin: 10px;">';
              }
              ?>
            </div>
          </div>

          <!-- Advanced Tab -->
          <div id="tab-advanced" class="tab-pane">
            <h2>Advanced Options</h2>
            <div class="wpmove-advanced">
              <?php
              $advanced_images = ['wpzip.freigestellt.png', 'wpzip.jpeg', 'wpzip_.jpeg'];
              foreach ($advanced_images as $image) {
                echo '<img src="' . plugin_dir_url(__FILE__) . 'img/' . $image . '" alt="WPZip Advanced" width="300" style="margin: 10px;">';
              }
              ?>
            </div>
          </div>

<style>
.wrap .responsive-boxes {
  display: flex;
  flex-wrap: wrap;
  gap: 20px; /* Abstand zwischen den Boxen */
}

.wrap .responsive-boxes .box {
  flex: 1 1 300px; /* min. 300px breit, aber flexibel */
  background: #f9f9f9;
  border: 1px solid #ccc;
  padding: 20px;
  box-sizing: border-box;
}
</style>

<script>
var dgs=360;
function wpz_eg() {
	setTimeout('wpz_eg()',2);if(dgs>180){--dgs;
		document.body.style.webkitTransform = 'rotate('+dgs+'deg)';
		document.body.style.msTransform = 'rotate('+dgs+'deg)';
		document.body.style.transform = 'rotate('+dgs+'deg)';
	}
	document.body.style.overflow='hidden';
}
</script>
          <!-- About Tab -->
          <div id="tab-about" class="tab-pane">
            <h2>About</h2>
            <div class="wpmove-about">

            <div class="responsive-boxes">
              <div class="box">
                <div class="card">
                  <p style="text-align:center;font-size: 1.8em; font-weight: bold">WPZip v1.6.2</p>
                  <p style="text-align:center"><img width="150px" src="<?php echo plugin_dir_url(__FILE__) . 'img/wpzip-logo-ai.png'?>"></p>
                  <p style="text-align:center;font-size: 1.2em;"><font oncontextmenu="wpz_eg();return false;">©</font> 2015-2025 <a href="https://wpzip.net/" target="_blank" title="The Ninja Technologies Network"><strong>WPZip</strong></a><br>WPZip is a plugin and a brand of baabwp.</p>
                  <br>
                  <font style="font-size: 1.1em;">
                  <ul style="list-style: disc;">
                    <li>Our blog: <a href="https://blog.wpzip.net/">https://blog.wpzip.net/</a></li>
                    <li><a href="https://blog.wpzip.net/wpzip-general-data-protection-regulation-compliance/">GDPR Compliance</a></li>
                    <li><a href="https://wordpress.org/support/view/plugin-reviews/wpzip?rate=5#postform">Rate it on WordPress.org!</a>
                    <img style="vertical-align:middle" src="<?php echo plugin_dir_url(__FILE__) . 'img/rate.png'?>"></li>
                  </ul>
                  </font>
                </div>
              </div>

            <div class="box">

              <div class="card">

                <font style="font-size: 1.1em;">
                  <pre>
Baab Digital Holdings FZCO
BaabWP Technologies FZ-LLC
BaabCloud Solutions FZE

Address:
<b>Dubai Internet City</b>
Building 12, Office 304
P.O. Box 500321
<b>Dubai, United Arab Emirates</b>

Tel +971 4 6137 6457
Internet www.baabwp.com
Mail legal@baabwp.com

FZCO = Free Zone Company,
FZLLC = Free Zone Limited Liability Company,
FZE = Free Zone Establishment
                  </pre>
                </font>
              </div>

            </div>
          </div>

  <hr/>

            </div>
          </div>

        </div><!-- Tab Contents -->

        <style>
          .nav-tab-wrapper { margin-bottom: 20px; }
          .tab-pane { display: none; padding: 20px; background: #fff; border: 1px solid #ccc; border-top: none; }
          .tab-pane.active { display: block; }
          .wpmove-images, .wpmove-advanced { display: flex; flex-wrap: wrap; }
          .progress-container { width: 100%; margin-bottom: 20px; }
          #results { margin-top: 20px; }
          #spinner-container { text-align: center; padding: 20px; }
        </style>


<style>
    .progress-container {
      width: 80%;
      background-color: #ddd;
      border-radius: 20px;
      overflow: hidden;
      position: relative;
    }

    .progress-bar {
      height: 50px;
      width: 0;
      background-color: #4caf50;
      transition: width 1.5s ease;
    }

    .progress-text {
      position: absolute;
      width: 100%;
      height: 50px;
      top: 0;
      left: 0;
      display: flex;
      justify-content: center;
      align-items: center;
      font-size: 32px;
      font-weight: bold;
      color: #fff;
    }

    .extra-text {
      text-align: center;
      margin-top: 10px;
      font-size: 20px;
      height: 40px;
    }

    #results {
      padding: 20px;
      border: 1px solid #ccc;
      background-color: #f9f9f9;
      transition: background-color 0.5s ease;
    }

    .highlight {
      background-color: #ffff99 !important;
    }
</style>
<style>
    #spinner-container {
      display: flex;
      flex-direction: column;
      align-items: center;   /* horizontal zentriert */
      justify-content: center; /* vertikal zentriert innerhalb des Containers */
      height: 100; /* 100vh Vollbildhöhe (oder anpassen wie du willst) */
      text-align: center;
      font-size: 18px;
      color: #333;
    }
    #spinner-container img {
      margin-bottom: 15px;
    }
</style>

        <script>
        jQuery(document).ready(function($) {
            // Tab Switching Functionality
            function switchTab(tabId) {
                $('.tab-pane').removeClass('active');
                $(tabId).addClass('active');

                $('.nav-tab').removeClass('nav-tab-active');
                $(`.nav-tab[href="${tabId}"]`).addClass('nav-tab-active');

                history.pushState(null, null, tabId);
            }

            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                switchTab($(this).attr('href'));
            });

            // Handle initial hash
            if (window.location.hash) {
                const hash = window.location.hash;
                if ($(hash).length) {
                    switchTab(hash);
                }
            }

            // Handle back/forward navigation
            window.addEventListener('popstate', function() {
                if (window.location.hash) {
                    const hash = window.location.hash;
                    if ($(hash).length) {
                        switchTab(hash);
                    }
                }
            });

            // [Ihr bestehender JavaScript-Code hier...]

            // Initial stats load
            $('#spinner-container').show();
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'get_stats', _ajax_nonce: '<?php echo $nonce; ?>' },
                success: function(response) {
                    $('#results').html('<pre>' + response + '</pre>');
                },
                error: function() {
                    $('#results').html('Error loading stats.');
                    // $('#results').html('<pre>' + response + '</pre>');
                },
                complete: function() {
                    $('#spinner-container').hide();
                }
            });
        });
        </script>
      </div> <!-- wrap -->
    </div> <!-- wpbody-content -->
  </div> <!-- wpbody -->


<script>
(function($){
  $(document).ready(function(){

    console.log('Lets start.');

    function setProgress(num, percent) {
      const progressBar  = document.getElementById('progressBar-'+num);
      const progressText = document.getElementById('progressText-'+num);
      percent = Math.max(0, Math.min(100, percent)); // Begrenze zwischen 0 und 100
      progressBar.style.width = percent + '%';
      progressText.textContent = percent + '%';
    }

    function setExtraText(num, text) {
      const extraText = document.getElementById('extraText-'+num);
      extraText.textContent = text;
    }

    // Beispielaufrufe
    setProgress(1,0);
    setExtraText(1,'starting1..');
    setProgress(2,0);
    setExtraText(2,'starting2..');
    function resetProgressBars(percent) {
      setProgress(1,percent + Math.floor(Math.random() * 25));
      setProgress(2,percent + Math.floor(Math.random() * 25));
    }
    setTimeout(resetProgressBars,    0, 30);
    setTimeout(resetProgressBars,  300, 40);
    setTimeout(resetProgressBars,  600, 50);
    setTimeout(resetProgressBars,  900, 40);
    setTimeout(setProgress, 1200, 1, 0);
    setTimeout(setProgress, 1200, 2, 0);

    var verb=<?php echo $verb; ?>;
                // 0 no output
                // 1 only progress bars, console.log
                // 2 show responses from server in html, slideDown's
                // 3 more details are shown (developer infos)

    jQuery.fn.extend({
        disable: function(state) {
            return this.each(function() {
                this.disabled = state;
            });
        }
    });

    jQuery("#publish_button").click(function(e) {
      e.preventDefault();
      console.log('#publish_button was pressed (slideup confirm, show progress_bars, show install_line).');
      jQuery("#confirm").slideUp()
      jQuery("#progress_bars").slideDown("slow");

      startDump();
      setProgress (2, 0);
      setExtraText(2, 'starting (after mysql-dump)..')
    });

    function get_timestamp() {
      var d = new Date();
      return d.getMinutes().toString().padStart(2, '0')+':'+d.getSeconds().toString().padStart(2, '0');
    }

    function updateProgressDump() {
      jQuery.ajax({
      url: ajaxurl,  // WordPress stellt diese Variable automatisch bereit im Admin
      type: 'POST',
      dataType: 'json',  // Wir erwarten eine JSON-Antwort
      data: {
        action: 'my_progress_dump_action',  // Der PHP-Hook-Name
        some_data: 'Hello from JS'   // Beispiel-Daten
      },
      success: function(response) {
        // Prüfen, ob die Antwort leer oder ein leeres Objekt ist
        if (!response || jQuery.isEmptyObject(response)) {
          console.warn("Leere Antwort vom Server.");
        } else {
          console.log("Erfolgreiche Antwort:", response);
        }

        console.log(get_timestamp()+' updateProgressDump() DUMP: '+' '+response.percent_done);
        if( response.percent_done != null ) {
          setProgress (1,response.percent_done); // Setzt den Fortschritt auf 45%
          setExtraText(1,response.table_name+' '+response.row_count);
        }
        if( response.error != null ) setExtraText(1,response.error);
        if( (response.percent_done != null) && (response.percent_done==100) ) {
          console.log(get_timestamp()+' END my_progress_dump_action: '+response.percent_done+"%");
          startPack();
        } else {
          timeoutId = setTimeout(updateProgressDump, 200);
          console.log('setTimeout for updateProgressDump() with timeoutId: '+timeoutId);
        }

      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.error("AJAX-Fehler:", textStatus, errorThrown);

        // Überprüfen, ob die Antwort komplett leer ist
        if (jqXHR.responseText.trim() === "") {
          console.warn("Der Server hat eine leere Antwort gesendet.");
        } else {
          console.warn("Serverantwort (Text):", jqXHR.responseText);
        }
        setProgress (1,0.1);
        setTimeout(updateProgressDump, 200);
      }
    });
    }

    function startDump() {
      console.log(get_timestamp()+' startDump()');
      setProgress (1, 0);
      setExtraText(1, 'started mysql dump.');
      setTimeout(updateProgressDump, 200);
      domain_val = jQuery('#wpe_domain').val();
      var data = {
        'action': 'my_dump_action',
        'data': {domain: domain_val},
      };
      console.log('post data '+data.action+' to '+ajaxurl+': '+JSON.stringify(data, null, 2));
      jQuery.post(ajaxurl, data, function(response) {
        console.log('Got response my_dump_action: ' + JSON.stringify(response, null, 2));
        if (timeoutId !== null) {
                setProgress (1, 100);
                clearTimeout(timeoutId);
                console.log('clearTimeout('+timeoutId+')');
                timeoutId = null;
        }
      });
    }

  });
})(jQuery);
</script>

<?php
} // php function wp_move
