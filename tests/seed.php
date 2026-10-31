<?php
// Run only on the disposable Trickle test sites with wp eval-file.
if (!in_array(basename(dirname(ABSPATH)), ['trickle-qa-a.localhost', 'trickle-qa-b.localhost'], true)) { throw new RuntimeException('Refusing to seed a non-test site.'); }
$local = str_contains(site_url(), 'trickle-qa-b.');
$mu = WPMU_PLUGIN_DIR . '/csm-test-environment.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
$ca = getenv('HOME') . '/Library/Application Support/Caddy/pki/authorities/local/root.crt';
file_put_contents($mu, "<?php\n// Disposable local test configuration.\ndefine('CSM_CA_BUNDLE', " . var_export($ca, true) . ");\nadd_filter('pre_wp_mail', '__return_false');\n");
update_option('_csm_settings', ['pull' => true, 'push' => true], false);
update_option('blogname', $local ? 'Trickle · Local lab' : 'Trickle · Remote lab');
$plugin = WP_PLUGIN_DIR . '/csm-fixture'; wp_mkdir_p($plugin);
file_put_contents($plugin . '/csm-fixture.php', "<?php\n/*\nPlugin Name: Stream Test Fixture\nVersion: " . ($local ? '1.2.0' : '0.9.0') . "\n*/\n");
file_put_contents($plugin . '/payload.txt', $local ? 'LOCAL FILE CONTENT' : 'REMOTE FILE CONTENT');
if (!$local) { file_put_contents($plugin . '/obsolete.php', "<?php // This old file must be removed when the plugin is replaced.\n"); }
$child = get_theme_root() . '/csm-child'; wp_mkdir_p($child);
file_put_contents($child . '/style.css', "/*\nTheme Name: Trickle Test Child\nTemplate: twentytwentyfive\nVersion: " . ($local ? '1.1.0' : '1.0.0') . "\n*/\nbody { --csm-fixture: 1; }\n");
global $wpdb;
$table = $wpdb->prefix . 'csm_fixture';
CSM_Support::query('CREATE TABLE IF NOT EXISTS ' . CSM_Support::ident($table) . ' (id bigint unsigned NOT NULL, payload longtext NOT NULL, binary_data longblob, PRIMARY KEY(id)) ENGINE=InnoDB');
CSM_Support::query('TRUNCATE TABLE ' . CSM_Support::ident($table));
for ($i = 1; $i <= 613; $i++) {
    $value = serialize(['origin' => $local ? 'local' : 'remote', 'url' => site_url() . '/sample/' . $i, 'nested' => serialize(['root' => ABSPATH]), 'object' => (object)['url' => home_url() . '/object']]);
    CSM_Support::query($wpdb->prepare('INSERT INTO ' . CSM_Support::ident($table) . ' (id,payload,binary_data) VALUES (%d,%s,%s)', $i, $value, "\0\xff\xfe\x01\n"));
}
$content = '<!-- wp:heading --><h2 class="wp-block-heading">Migration test library</h2><!-- /wp:heading -->';
$content .= '<!-- wp:paragraph --><p>Images, serialized settings, selected plugin files, and a custom table live here.</p><!-- /wp:paragraph -->';
$post = wp_insert_post(['post_title' => 'Stream migration test page', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => $content]);
update_post_meta($post, 'csm_nested_fixture', ['origin' => $local ? 'local' : 'remote', 'url' => site_url() . '/nested', 'serialized' => serialize(['url' => site_url()])]);
update_option('csm_fixture_origin', $local ? 'local' : 'remote');
echo "Seeded " . ($local ? 'local' : 'remote') . " fixture, child theme and 613-row custom table.\n";
