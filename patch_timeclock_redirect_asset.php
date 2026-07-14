<?php

$matches = glob(__DIR__.'/public/build/assets/TimeclockUnlock-*.js');
$path = $matches[0] ?? '';
$source = file_get_contents($path);
$old = 'U.value=L.data.message??"Timeclock unlocked.",Q.visit(L.data.redirect??"/")}catch(r)';
$new = 'U.value=L.data.message??"Timeclock unlocked.";const ne=L.data.redirect??"/";if(ne.startsWith("/admin")){window.location.assign(ne);return}Q.visit(ne)}catch(r)';

if (! str_contains($source, $old)) {
    fwrite(STDERR, "pattern not found\n");
    exit(1);
}

file_put_contents($path, str_replace($old, $new, $source));
