<?php
// cove wp trickle-qa-a eval-file tests/media.php dry-run|apply|cleanup
if (wp_parse_url(get_option('home'), PHP_URL_HOST) !== 'trickle-qa-a.localhost' || !str_starts_with(realpath(ABSPATH), realpath(getenv('HOME') . '/Cove/Sites') . '/')) { throw new RuntimeException('Media checks require the isolated QA-A source.'); }
$state_path = CSM_Support::private_dir() . '/media-ui-fixture.json';
$uploads = wp_upload_dir(null, false);
$cleanup = static function (array $state) use ($uploads, $state_path): void {
    if (!str_starts_with($state['dir'], $uploads['basedir'] . '/trickle-media-qa-')) { throw new RuntimeException('Unsafe fixture directory.'); }
    foreach ($state['ids'] as $id) {
        if (get_post_meta($id, '_trickle_media_fixture', true) === $state['marker']) { wp_delete_attachment($id, true); }
    }
    CSM_Support::remove_tree($state['dir']);
    if (is_file($state_path)) { unlink($state_path); }
};
$mode = $args[0] ?? 'dry-run';
if ($mode === 'cleanup') {
    if (is_file($state_path)) { $cleanup(json_decode(file_get_contents($state_path), true, 512, JSON_THROW_ON_ERROR)); }
    echo "Disposable media fixtures removed.\n"; return;
}
$base = get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','numberposts'=>3,'orderby'=>'ID','order'=>'ASC']);
if (count($base) < 3) { throw new RuntimeException('Expected seeded test images.'); }
if ($mode === 'dry-run') { echo "Will create 64 isolated image attachments with copied originals/thumbnails and known upload dates, then verify dates, pagination and file selection.\n"; return; }
if ($mode !== 'apply' || is_file($state_path)) { throw new RuntimeException('Use dry-run, apply or cleanup; clean up earlier fixtures first.'); }
$admins = get_users(['role'=>'administrator','number'=>1]); wp_set_current_user($admins[0]->ID);
$marker = bin2hex(random_bytes(6)); $folder = 'trickle-media-qa-' . $marker;
$state = ['marker'=>$marker,'dir'=>$uploads['basedir'].'/'.$folder,'ids'=>[]];
wp_mkdir_p($state['dir']); $checks = 0;
$check = static function ($ok, $message) use (&$checks) { if (!$ok) { throw new RuntimeException($message); } $checks++; echo "PASS $message\n"; };
try {
    for ($i=0; $i<64; $i++) {
        $original = $base[$i % count($base)]; $source = get_attached_file($original->ID); $meta = wp_get_attachment_metadata($original->ID);
        $name = sprintf('image-%03d', $i); $file = $name . '.' . pathinfo($source, PATHINFO_EXTENSION);
        if (!copy($source, $state['dir'].'/'.$file)) { throw new RuntimeException('Cannot copy media fixture.'); }
        $meta['file'] = $folder . '/' . $file;
        foreach (array_keys($meta['sizes'] ?? []) as $size) {
            $data = &$meta['sizes'][$size];
            $new = $name . '-' . $size . '.' . pathinfo($data['file'], PATHINFO_EXTENSION);
            if (!copy(dirname($source).'/'.$data['file'], $state['dir'].'/'.$new)) { throw new RuntimeException('Cannot copy image size fixture.'); }
            $data['file'] = $new;
        }
        unset($data);
        if (!empty($meta['original_image'])) {
            $new = $name . '-original.' . pathinfo($meta['original_image'], PATHINFO_EXTENSION);
            copy(dirname($source).'/'.$meta['original_image'], $state['dir'].'/'.$new); $meta['original_image'] = $new;
        }
        $date = $i<61 ? (new DateTimeImmutable('2026-10-15 12:00:00',new DateTimeZone('UTC')))->modify('-'.$i.' days')->format('Y-m-d H:i:s') : ['2020-01-01 00:00:00','2020-01-02 12:00:00','2020-01-03 23:59:59'][$i-61];
        $id = wp_insert_attachment(['post_title'=>'Media selection fixture '.($i+1),'post_status'=>'inherit','post_mime_type'=>get_post_mime_type($original),'post_date'=>$date,'post_date_gmt'=>$date],$state['dir'].'/'.$file,0,true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        $state['ids'][]=$id; update_post_meta($id,'_trickle_media_fixture',$marker); wp_update_attachment_metadata($id,$meta);
    }
    wp_update_post(['ID'=>$state['ids'][0],'post_title'=>'Media selection fixture 1 updated later']);
    $one=CSM_Files::media(); $two=CSM_Files::media(2);
    $check(count($one['media'])===60 && $one['media_more'] && count($two['media'])===$one['media_total']-60 && !$two['media_more'],'Media library pages cover attachments beyond the first 60');
    $all=array_merge($one['media'],$two['media']); $dates=array_column($all,'uploaded'); $sorted=$dates; rsort($sorted);
    $check($dates===$sorted,'Attachments are ordered by upload date descending, not ID');
    $check(count(array_unique(array_column($all,'id')))===count($all),'Pagination has no duplicate attachment IDs');
    $sample=CSM_Files::media(1,[$state['ids'][0]])['media'][0];
    $check($sample['uploaded']==='2026-10-15T12:00:00Z' && str_replace(' ','T',$sample['date']).'Z'!==$sample['uploaded'],'Upload date is separate from later modification time');
    $check((bool)$sample['preview'] && str_contains($sample['preview'],$folder),'Thumbnail URL uses the copied source image metadata');
    $selection=CSM_Files::selection(['media'=>'uploaded','media_after'=>'2020-01-02','media_before'=>'2020-01-03']);
    $paths=iterator_to_array(CSM_Files::paths($selection)); $names=array_column($paths,0);
    $check(count($paths)>=4 && !array_filter($names,static fn($p)=>!str_contains($p,'image-062')&&!str_contains($p,'image-063')),'Inclusive upload-date range selects only the correct attachments, beyond the first page');
    $check((bool)array_filter($names,static fn($p)=>str_contains($p,'-thumbnail.')),'Upload-date transfer includes generated thumbnail files');
    $selected=CSM_Files::selection(['media'=>'selected','media_ids'=>[$state['ids'][63]]]);
    $chosen=iterator_to_array(CSM_Files::paths($selected));
    $check(count($chosen)>=2 && !array_filter(array_column($chosen,0),static fn($p)=>!str_contains($p,'image-063')),'Selected attachment includes its original and image sizes');
    foreach ([['',''],['2020-02-30',''],['2021-01-01','2020-01-01']] as [$after,$before]) {
        $rejected=false; try { CSM_Files::media_selection(['media'=>'uploaded','media_after'=>$after,'media_before'=>$before]); } catch(Throwable $e) { $rejected=true; }
        $check($rejected,'Invalid or empty upload-date range is rejected');
    }
    $rejected=false; try { CSM_Files::media(1,[-1]); } catch(Throwable $e) { $rejected=true; }
    $check($rejected,'Media browser validates requested attachment IDs');
    file_put_contents($state_path,json_encode($state)); chmod($state_path,0600);
    echo "$checks media checks passed. Fixtures retained temporarily for browser QA.\n";
} catch(Throwable $e) { $cleanup($state); throw $e; }
