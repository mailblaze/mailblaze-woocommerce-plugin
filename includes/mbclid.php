<?php
/**
 * Structural check for Mail Blaze conversion click ids.
 *
 * Shared with mailblaze-tracking and mailblaze-commerce:
 *   v1.{campaignUid}.{subscriberUid}.{urlHash}.{unix}.{hmac16}
 *
 * Commerce verifies the HMAC and the 30-day window. This plugin does not
 * hold MBCLID_SECRET, so it only keeps tokens those services can parse.
 * Field bodies are alphanumeric, matching campaign UIDs, subscriber ids,
 * and the published test vector. The signature is 16 lowercase hex chars.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param mixed $raw
 * @return string|null Token unchanged, or null when it is not a v1 mbclid.
 */
function mailblaze_wc_normalize_mbclid($raw) {
    if (!is_string($raw)) {
        return null;
    }

    $token = trim($raw);
    $pattern = '/^v1\.([A-Za-z0-9]+)\.([A-Za-z0-9]+)\.([A-Za-z0-9]*)\.([0-9]+)\.([0-9a-f]{16})$/';
    if (!preg_match($pattern, $token)) {
        return null;
    }

    return $token;
}
