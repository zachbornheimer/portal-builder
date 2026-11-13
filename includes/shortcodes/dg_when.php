<?php

/**
 * [dg_when portal="25657" which="close"]
 *
 * which: open|close. part: phrase|date. Blank display uses the actual instant.
 */

function dg_when_shortcode($atts)
{
    $atts = shortcode_atts(
        array(
            'portal' => 0,
            'which' => 'close',
            'part' => 'phrase',
        ),
        $atts,
        'dg_when',
    );
    if (!class_exists('Portal_When') || !class_exists('Portal_Definition')) {
        return '';
    }

    $portal_id = (int) $atts['portal'];
    if ($portal_id < 1 && function_exists('get_the_ID')) {
        $portal_id = (int) get_the_ID();
    }
    $definition = Portal_Definition::load_for_post($portal_id);
    if (!is_array($definition)) {
        return '';
    }
    $publish = isset($definition['publish']) && is_array($definition['publish'])
        ? $definition['publish']
        : array();
    $which = 'open' === $atts['which'] ? Portal_When::WHICH_OPEN : Portal_When::WHICH_CLOSE;
    $instant = Portal_When::shown_instant($publish, $which);
    $timezone = Portal_When::timezone($publish);
    $text = 'date' === $atts['part']
        ? Portal_When::date_label($instant, $timezone)
        : Portal_When::phrase($instant, $timezone);
    return esc_html($text);
}

add_shortcode('dg_when', 'dg_when_shortcode');

/**
 * Tell hosts the schedule changed. DragonGate does not know their post types.
 *
 * @param int    $meta_id Unused.
 * @param int    $post_id Portal post id.
 * @param string $key     Meta key.
 * @param mixed  $value   Definition JSON.
 */
function dg_when_announce_schedule($meta_id, $post_id, $key, $value)
{
    unset($meta_id);
    if ('_portal_definition' !== $key || !class_exists('Portal_When')) {
        return;
    }
    $definition = json_decode((string) $value, true);
    if (!is_array($definition)) {
        return;
    }
    $publish = isset($definition['publish']) && is_array($definition['publish'])
        ? $definition['publish']
        : array();
    do_action('dg_portal_schedule_saved', (int) $post_id, $publish);
}

add_action('added_post_meta', 'dg_when_announce_schedule', 10, 4);
add_action('updated_post_meta', 'dg_when_announce_schedule', 10, 4);

