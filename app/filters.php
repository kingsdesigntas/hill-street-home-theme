<?php

namespace App;

/**
 * Add <body> classes
 */
add_filter('body_class', function (array $classes) {
  /** Add page slug if it doesn't exist */
  if (is_single() || is_page() && !is_front_page()) {
    if (!in_array(basename(get_permalink()), $classes)) {
      $classes[] = basename(get_permalink());
    }
  }

  /** Add class if sidebar is active */
  if (display_sidebar()) {
    $classes[] = 'sidebar-primary';
  }

  /** Clean up class names for custom templates */
  $classes = array_map(function ($class) {
    return preg_replace(['/-blade(-php)?$/', '/^page-template-views/'], '', $class);
  }, $classes);

  return array_filter($classes);
});

/**
 * Add "… Continued" to the excerpt
 */
add_filter('excerpt_more', function () {
  return ' &hellip; <a href="' . get_permalink() . '">' . __('Continued', 'sage') . '</a>';
});

/**
 * Template Hierarchy should search for .blade.php files
 */
collect([
  'index', '404', 'archive', 'author', 'category', 'tag', 'taxonomy', 'date', 'home',
  'frontpage', 'page', 'paged', 'search', 'single', 'singular', 'attachment', 'embed',
])->map(function ($type) {
  add_filter("{$type}_template_hierarchy", __NAMESPACE__ . '\\filter_templates');
});

/**
 * Render page using Blade
 */
add_filter('template_include', function ($template) {
  collect(['get_header', 'wp_head'])->each(function ($tag) {
    ob_start();
    do_action($tag);
    $output = ob_get_clean();
    remove_all_actions($tag);
    add_action($tag, function () use ($output) {
      echo $output;
    });
  });
  $data = collect(get_body_class())->reduce(function ($data, $class) use ($template) {
    return apply_filters("sage/template/{$class}/data", $data, $template);
  }, []);
  if ($template) {
    echo template($template, $data);
    return get_stylesheet_directory() . '/index.php';
  }
  return $template;
}, PHP_INT_MAX);

/**
 * Render comments.blade.php
 */
add_filter('comments_template', function ($comments_template) {
  $comments_template = str_replace(
    [get_stylesheet_directory(), get_template_directory()],
    '',
    $comments_template
  );

  $data = collect(get_body_class())->reduce(function ($data, $class) use ($comments_template) {
    return apply_filters("sage/template/{$class}/data", $data, $comments_template);
  }, []);

  $theme_template = locate_template(["views/{$comments_template}", $comments_template]);

  if ($theme_template) {
    echo template($theme_template, $data);
    return get_stylesheet_directory() . '/index.php';
  }

  return $comments_template;
}, 100);

/// Custom stuff

/**
 * Use Lozad (lazy loading) for attachments/featured images
 */
add_filter('wp_get_attachment_image_attributes', function ($attr, $attachment) {
  // Bail on admin
  if (is_admin()) {
    return $attr;
  }

  //Only run lazy load in first laod
  if (isset($_SERVER['HTTP_X_BARBA'])) {
    return $attr;
  }

  //TODO
  return $attr;
  //if(isset($attr['data-no-lazyload'])) return;

  $attr['data-src'] = $attr['src'];
  $attr['data-srcset'] = $attr['srcset'];
  $attr['class'] .= ' lozad';
  unset($attr['src']);
  unset($attr['srcset']);

  return $attr;
}, 10, 2);

// =================================================
//
// Store chooser
//
// =================================================

/**
 * Get store chooser data from cookie
 */
function get_sc_data() {
  //if (class_exists('\WC_OrderByLocation')) {
  if (isset($_COOKIE[wc_sc_cookie_name()])) {
    $cookie_data_raw = $_COOKIE[wc_sc_cookie_name()];
    try {
      $cookie_data = \json_decode(stripslashes($cookie_data_raw), true);
      return $cookie_data;

    } catch (Exception $e) {

    }
  }
  //}
  return null;
}

/**
 * Helpers for store chooser
 */
function sc_method_is($method) {
  $sc_data = get_sc_data();
  return (isset($sc_data['method']) && strcasecmp($sc_data['method'], $method) === 0);
}

function sc_location_is($location) {
  $sc_data = get_sc_data();
  return (isset($sc_data['location']) && strcasecmp($sc_data['location'], $location) === 0);
}

function sc_postcode_is($test) {
  $sc_data = get_sc_data();
  return (isset($sc_data['postcode']) && strcasecmp($sc_data['postcode'], $test) === 0);
}

function sc_suburb_is($test) {
  $sc_data = get_sc_data();
  return (isset($sc_data['suburb']) && strcasecmp($sc_data['suburb'], $test) === 0);
}

/**
 * Inject the location from our own cookie
 */
// add_filter('wc_obl/location', function ($location) {
//   $sc_data = get_sc_data();
//   if ($sc_data && isset($sc_data['location'])) {
//     return $sc_data['location'];
//   }

//   return $location;
//}, 10, 1);

/**
 * For a given postcode, get the location term slug
 * Defaults to west-hobart
 * Checks list of 'devonport' postcodes from ACF
 */
function postcode_to_location($postcode) {
  // $default_location = 'west-hobart';
  // $test_location = 'devonport';
  // $postcodes = get_field('postcodes_devonport', 'options');
  // /*if (empty($postcodes)) {
  // return $default_location;
  // }*/

  // $return_location = $default_location;

  // foreach (explode("\n", $postcodes) as $testPostcode) {
  //   //if (strcasecmp($postcode, $testPostcode) === 0) {
  //   if (trim($postcode) == trim($testPostcode)) {
  //     //return $test_location;
  //     $return_location = $test_location;
  //     break;
  //   }
  // }
  // return $return_location;

  //Get all locations
  $locations = get_terms(array(
    'taxonomy' => 'location',
    'hide_empty' => false,
  ));
  $fallback_location = null;
  $return_location = null;

  foreach ($locations as $location_term) {
    $is_delivery_enabled = get_field('is_delivery_enabled', $location_term);
    //if this location doesn't do delivery, just skip. Currently we don't actually use the 'delivery_location' (e.g. the assigned fulfilment store for this location)
    //$delivery_location = get_field('delivery_location', $location_term);
    if (!$is_delivery_enabled) {
      continue;
    }

    //If this is the fallback store, assign that to the fallback
    $is_fallback = get_field('is_fallback', $location_term);
    if ($is_fallback) {
      $fallback_location = $location_term->slug;
    }

    //See if the requested postcode belongs to this store
    $delivery_postcodes = array_map(function ($pc) {return trim($pc);}, explode("\n", get_field('delivery_postcodes', $location_term)));
    if (!is_array($delivery_postcodes)) {
      $delivery_postcodes = [];
    }

    if (!empty($delivery_postcodes) && in_array($postcode, $delivery_postcodes)) {
      $return_location = $location_term->slug;
      break;
    }
  }

  if (!$return_location) {
    $return_location = $fallback_location;
  }

  return $return_location;
}

/**
 * Ajax postcode search
 */
add_action('wp_ajax_postcode_search', __NAMESPACE__ . '\\ajax_postcode_search');
add_action('wp_ajax_nopriv_postcode_search', __NAMESPACE__ . '\\ajax_postcode_search');
function ajax_postcode_search() {
  $q = isset($_POST['q']) ? $_POST['q'] : null;
  if (!$q) {
    wp_send_json_error(array('message' => 'Missing query'), 400);
    wp_die();
  }

  //Try getting from cache first, API calls are slow
  $cacheKey = 'auspost_api_postcode';
  $transientKey = $cacheKey . '_' . $q;

  $suburbs = get_transient($transientKey);

  if (!$suburbs) {

    $api_key = 'e6b27996-be38-424e-9d66-14fddc860c34';
    $api_url = 'https://digitalapi.auspost.com.au/postcode/search.txt?';
    $query = implode('&', ['q=' . urlencode($q), 'limit=10']);
    $resp = wp_remote_get($api_url . $query, array(
      'headers' => [
        'auth-key' => $api_key,
      ],
    ));
    if (is_wp_error($resp)) {
      error_log('AusPost fetch failed');
      wp_send_json_error(array('message' => 'Remote fetch failed'), 500);
      wp_die();
    }
    $content = trim(wp_remote_retrieve_body($resp));

    $rawSuburbs = explode("\n", $content);

    $suburbs = [];
    foreach ($rawSuburbs as $rawSuburb) {
      $parts = explode("|", $rawSuburb);
      if (count($parts) < 4) {
        continue;
      }

      $suburbs[] = [
        'name' => $parts[2],
        'suburb' => $parts[2],
        'postcode' => $parts[1],
        'state' => $parts[3],
        'location' => postcode_to_location($parts[1]),
      ];
    }

    set_transient($transientKey, $suburbs, 60 * 60 * 24 * 3600); // cache for a year. how often to postcodes/suburbs change?
  }

  wp_send_json($suburbs);
  //wp_send_json(array(['name' => 'Hobart'], ['name' => 'West Hobart'], ['name' => 'Kingston']));
  wp_die();
}

/**
 * Remove scripts/styles from order-by-location
 */
add_filter('wc_obl/enqueue_frontend', '__return_false');

/**
 * Get cart count
 */
add_action('wp_ajax_cart_count', __NAMESPACE__ . '\\ajax_cart_count');
add_action('wp_ajax_nopriv_cart_count', __NAMESPACE__ . '\\ajax_cart_count');
function ajax_cart_count() {
  global $woocommerce;
  $count = $woocommerce->cart->cart_contents_count;
  $count_string = sprintf(_n('(%d item)', '(%d items)', $count, 'hillsthome'), $count);
  $html = null;
  if ($count > 0) {
    $html = '<span class="cart-count text-blue-500 text-sm">' . $count_string . '</span>';
  }
  wp_send_json(['html' => $html]);
  exit;
}

/**
 * Show notice
 */
add_action('get_header', function () {
  if (is_admin()) {
    return;
    // shouldnt happen but hey
  }

  $field = get_field('store_notice', 'options');
  if ($field && isset($field['enabled'], $field['dismissible'], $field['content'], $field['position']) && $field['enabled']) {
    $id = md5($field['content']);

    ?>
  <div id="site-notice-<?php echo $id; ?>" class="site-notice hidden w-full bg-yellow-200  z-50 <?php echo $field['position'] === 'top_sticky' ? 'fixed top-0 left-0' : 'relative'; ?>" data-id="<?php echo $id; ?>" data-dismissible="<?php echo $field['dismissible'] ? 'true' : 'false'; ?>">
  <?php if ($field['dismissible']): ?>
  <button
			aria-label="<?php esc_html_e('Dismiss site notice', 'hillsthome');?>"
			class="site-notice-dismiss position absolute right-0 top-0 mr-2 mt-3 w-6 h-6 text-xl flex items-center justify-center"
		>
			<svg class="icon" stroke="currentColor" fill="currentColor" stroke-width="0" viewBox="0 0 24 24" height="1em" width="1em" xmlns="http://www.w3.org/2000/svg"> <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"> </path> </svg>
		</button>
    <?php endif;?>
    <div class="p-3 mr-10">
      <?php echo $field['content']; ?>
    </div>
  </div>
    <?php
}
});