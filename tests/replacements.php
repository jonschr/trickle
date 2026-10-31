<?php
/** Read-only replacement checks; run with wp eval-file after loading Trickle. */
if (!defined('ABSPATH') || !class_exists('CSM_Support')) { throw new RuntimeException('Load WordPress and Trickle first.'); }
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($label); }
    $checks++; echo "PASS $label\n";
};
$pairs = ['https://source.test' => 'https://destination.test', '/www/wp-content' => '/local/content', '/www/' => '/local/'];
$malformed = 'a:1:{s:3:"old";s:999:"legacy";}';
$check(CSM_Support::replace($malformed, $pairs) === $malformed, 'Unrelated malformed serialization remains byte-for-byte intact');
$check(CSM_Support::replace('C:7:"Unknown":3:{old}', $pairs) === 'C:7:"Unknown":3:{old}', 'Unrelated unsupported serialization remains intact');
$check(CSM_Support::replace('unchanged', $pairs) === 'unchanged', 'Unrelated plain values remain intact');
$check(CSM_Support::replace($malformed, []) === $malformed, 'Disabled replacements leave malformed values intact');
$nested = serialize(['url' => 'https://source.test/page', 'path' => '/www/wp-content/uploads', 'legacy' => $malformed, 'nested' => serialize(['url' => 'https://source.test/nested'])]);
$actual = unserialize(CSM_Support::replace($nested, $pairs), ['allowed_classes' => false]);
$check($actual['url'] === 'https://destination.test/page', 'Serialized string lengths follow URL replacement');
$check($actual['path'] === '/local/content/uploads', 'Longest path match wins without cascading');
$check($actual['legacy'] === $malformed, 'Changed parent preserves unrelated malformed nested string');
$check(unserialize($actual['nested'])['url'] === 'https://destination.test/nested', 'Nested serialized values remain valid');
$targeted = 'a:1:{s:3:"url";s:999:"https://source.test";}';
$threw = false;
try { CSM_Support::replace($targeted, $pairs); } catch (RuntimeException $e) { $threw = true; }
$check($threw, 'Targeted malformed serialization still stops rather than corrupting data');
class Trickle_Replacement_Fixture {
    public string $url = 'https://source.test/object';
    public function __wakeup(): void { $GLOBALS['trickle_replacement_wakeup'] = true; }
}
$record = CSM_Support::replace(serialize(new Trickle_Replacement_Fixture()), $pairs);
$actual = (array)unserialize($record, ['allowed_classes' => false]);
$check($actual['url'] === 'https://destination.test/object' && empty($GLOBALS['trickle_replacement_wakeup']), 'Object records are rewritten without instantiation');
echo "$checks replacement checks passed.\n";

$src = ['url'=>'https://source.test/site','home'=>'https://source.test','root'=>'/srv/wp/','content'=>'/srv/wp/wp-content'];
$dst = ['url'=>'https://target.test/site','home'=>'https://target.test','root'=>'/dev/wp/','content'=>'/dev/wp/wp-content'];
$defaults = CSM_Database::replacement_pairs($src,$dst,[]);
$check(CSM_Support::replace('http://source.test/site/a https://source.test/b //source.test/c',$defaults)==='https://target.test/site/a https://target.test/b //target.test/c','Default mappings cover HTTP, HTTPS and protocol-relative URLs');
$json = '{"url":"https:\\/\\/source.test\\/site\\/a","other":12}';
$check(json_decode(CSM_Support::replace($json,$defaults),true)['url']==='https://target.test/site/a','JSON escaped URLs are replaced without rewriting unrelated JSON');
$nested = serialize(['json'=>$json,'url'=>'http://source.test/site/x']);
$v=unserialize(CSM_Support::replace($nested,$defaults));
$check(json_decode($v['json'],true)['url']==='https://target.test/site/a' && $v['url']==='https://target.test/site/x','Escaped URL replacements preserve enclosing serialization lengths');
