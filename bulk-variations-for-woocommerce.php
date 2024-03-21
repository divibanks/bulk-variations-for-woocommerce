<?php
/**
 * Plugin Name: Bulk Variations for WooCommerce
 * Plugin URI: https://wpcorner.co
 * Description: WooCommerce bulk variations with global price adjustments.
 * Version: 1.1.6
 * Author: WP Corner
 * Author URI: https://wpcorner.co
 * WC requires at least: 4.0
 * WC tested up to: 8.2.1
 * License: GNU General Public License v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

/**
 * Enqueue main JavaScript to automatically switch and select variations
 */
function wcbv_enqueue_scripts() {
    wp_enqueue_script('wcbv-main', plugins_url('/assets/js/main.js', __FILE__), [], '1.1.6', true);
}

add_action('wp_enqueue_scripts', 'wcbv_enqueue_scripts');

/**
 * Get the absolute numeric value of a string as integer or float
 */
function wcbv_get_numeric($val) {
    if (is_numeric($val)) {
        return $val + 0;
    }

    return 0;
}

/**
 * Create a product variation for a defined variable product ID.
 *
 * @source https://stackoverflow.com/questions/47518280/create-programmatically-a-woocommerce-product-variation-with-new-attribute-value
 * @source https://stackoverflow.com/questions/42113840/auto-add-all-product-attributes-when-adding-a-new-product-in-woocommerce
 *
 * @since 1.0.0
 * @param int   $product_id | Post ID of the product parent variable product.
 * @param array $variation_data | The data to insert in the product.
 */
function wcbv_create_product_variation($product_id, $variation_data) {
    // Get the variable product object (parent)
    $product = wc_get_product($product_id);

    // Get pricing adjustment
    $ppa = $variation_data['pricing_adjustment'];

    $variation_data = [
        'attributes' => $variation_data['attributes'],
        'sku' => isset($variation_data['sku']) ? $variation_data['sku'] : '',
    ];

    $variation = new WC_Product_Variation();
    $variation->set_parent_id($product_id);
    $variation->set_props($variation_data);

    // Iterating through the variations attributes
    foreach ($variation_data['attributes'] as $attribute => $term_name) {
        $taxonomy = 'pa_' . $attribute; // The attribute taxonomy

        // If taxonomy doesn't exist, create it
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'product_variation', [
                'hierarchical' => false,
                'label' => ucfirst($attribute),
                'query_var' => true,
                'rewrite' => ['slug' => sanitize_title($attribute)]
            ]);
        }

        // Check if the Term name exists, and if not, create it
        if (!term_exists($term_name, $taxonomy)) {
            wp_insert_term($term_name, $taxonomy);
        }

        $term_slug = get_term_by('name', $term_name, $taxonomy)->slug; // Get the term slug

        // Get the post Terms names from the parent variable product
        $post_term_names = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);

        // Check if the post term exists, and if not, set it in the parent variable product
        if (!in_array($term_name, $post_term_names)) {
            wp_set_post_terms($product_id, $term_name, $taxonomy, true);
        }

        // Set/save the attribute data in the product variation
        $variation->set_attribute($taxonomy, $term_slug);
    }

    // Set/save all other data
    $product->set_manage_stock(true);

    if (is_numeric($ppa)) {
        $defaultPrice = $product->get_price() * (1 + wcbv_get_numeric($ppa) / 100);
    } else {
        $defaultPrice = $product->get_price();
    }

    $variation->set_price($defaultPrice);
    $variation->set_regular_price($defaultPrice);
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity($product->get_stock_quantity());

    $variation_id = $variation->save(); // Save the data

    return $variation_id;
}

function wcbv_menu_links() {
    add_submenu_page('woocommerce', 'Bulk Variations', 'Bulk Variations', 'manage_options', 'wcbv', 'wcbv_build_admin_page');
}

add_action('admin_menu', 'wcbv_menu_links');

function wcbv_build_admin_page() {
    $tab = (filter_has_var(INPUT_GET, 'tab')) ? filter_input(INPUT_GET, 'tab') : 'dashboard';
    $section = 'admin.php?page=wcbv&amp;tab='; ?>

    <div class="wrap">
        <h1>WooCommerce Bulk Variations with Global Price Adjustments</h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo $section; ?>dashboard" class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">Dashboard</a>
            <a href="<?php echo $section; ?>help" class="nav-tab <?php echo $tab === 'help' ? 'nav-tab-active' : ''; ?>">Help</a>
        </h2>

        <?php if ($tab === 'dashboard') { ?>
            <h2>Add New Bulk Variation</h2>
            <p>Add a new variation attribute to <b>all</b> products.</p>

            <?php
            if (isset($_POST['save_licence_settings'])) {
                $newShowName = sanitize_text_field($_POST['wcbv_variation']);
                $newAttributeName = sanitize_title($_POST['wcbv_attribute']);
                $newShowPricingAdjustment = sanitize_text_field($_POST['wcbv_variation_adjustment']);

                // Variation data
                $variation_data =  [
                    'attributes' => [
                        $newAttributeName => $newShowName,
                    ],
                    'pricing_adjustment' => $newShowPricingAdjustment
                ];

                // The function to be run
                $args = [
                    'post_type' => 'product',
                    'posts_per_page' => -1
                ];
                $loop = new WP_Query($args);

                if ($loop->have_posts()) {
                    while ($loop->have_posts()) : $loop->the_post();
                        $product_id = get_the_ID();
                        $product = wc_get_product($product_id);
                        if ($product && $product->is_type('variable')) {
                            wcbv_create_product_variation($product_id, $variation_data);
                        }
                    endwhile;
                }

                echo '<div class="updated notice is-dismissible"><p>Variation(s) added successfully!</p></div>';
            }
            ?>

            <form method="post">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wcbv_variation">Name</label></th>
                            <td>
                                <input type="text" name="wcbv_variation" value="<?php echo get_option('wcbv_variation'); ?>" class="regular-text">
                                <br><small>Use a short, descriptive name. It will appear as a product variation selector on the landing page.</small>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcbv_attribute">Attribute Name</label></th>
                            <td>
                                pa_<input type="text" name="wcbv_attribute" value="<?php echo get_option('wcbv_attribute'); ?>" class="regular-text">
                                <br><small>Use an <a href="<?php echo admin_url('edit.php?post_type=product&page=product_attributes'); ?>">existing attribute</a> name (e.g. <code>shows</code>, <code>exhibitions</code>).</small>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wcbv_variation_adjustment">Price Adjustment (%)</label></th>
                            <td>
                                <input type="text" name="wcbv_variation_adjustment" value="<?php echo get_option('wcbv_variation_adjustment'); ?>" class="regular-text">%
                                <br><small>Use percentages (e.g. <code>10</code>, <code>-20</code>). Currency is applied automatically.</small>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><input type="submit" name="save_licence_settings" class="button button-primary" value="Save Changes"></th>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </form>

            <hr>
            <p>&copy;<?php echo date('Y'); ?> <a href="https://getbutterfly.com/" rel="external"><strong>getButterfly</strong>.com</a> &middot; <small>Code wrangling since 2005</small></p>
        <?php } else if ($tab === 'help') { ?>
            <h2>Help</h2>

            <p>Use the <code>[variations attribute="shows"]</code> on any page to show the landing page.</p>
            <p>The <code>shows</code> attribute will be turned into <code>pa_shows</code> and all children (terms) will be displayed in a simple grid.</p>

            <hr>
            <p>&copy;<?php echo date('Y'); ?> <a href="https://getbutterfly.com/" rel="external"><strong>getButterfly</strong>.com</a> &middot; <small>Code wrangling since 2005</small></p>
        <?php } ?>
    </div>
    <?php
}

function wcbv_variation_landing($atts) {
    $attributes = shortcode_atts([
        'attribute' => 'pa_'
    ], $atts);

    $out = '<div class="wcbv-grid">';

    $terms = get_terms('pa_' . $attributes['attribute']); // e.g. pa_shows

    foreach ($terms as $term) {
        $termName = sanitize_title($term->name);

        $out .= '<div class="wcbv-grid--cell">
            <a href="/?v=' . $termName . '" data-show-id="' . $term->term_id . '">' . $term->name . '</a>
        </div>';
    }

    $out .= '</div>';

    return $out;
}

add_shortcode('variations', 'wcbv_variation_landing');

function wcbv_cookie_redirect() {
    if (isset($_GET['v'])) {
        if (sanitize_title($_GET['v']) !== '') {
            $v = sanitize_title($_GET['v']);

            $urlParts = parse_url(home_url());
            $domain = $urlParts['host'];

            setcookie('wcbv', $v, time() + 30*24*60*60, '/', $domain); // 30 days
        }
    }
}
add_action('parse_request', 'wcbv_cookie_redirect', 0);
