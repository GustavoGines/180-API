<?php

function check($searchNormalized, $target)
{
    similar_text($searchNormalized, $target, $percent);

    if (str_contains($target, $searchNormalized) || str_contains($searchNormalized, $target)) {
        $percent += 20;
    }
    echo "'$searchNormalized' vs '$target' -> $percent %\n";
}

check('torta decorada', 'micro torta');
check('torta', 'micro torta');
check('torta chantilly', 'micro torta');
check('torta', 'torta chantilly');
check('torta decorada', 'torta chantilly');
