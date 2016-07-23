<?php
if (!function_exists('array_get')) {
    function array_get($array, $key, $default = null)
    {
        if (is_null($key)) {
            return $array;
        }

        if (isset($array[$key])) {
            return $array[$key];
        }

        return $default;
    }
}

if (!function_exists('quick_random')) {
    function quick_random($length, $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
    {
        $max = strlen($pool) - 1;  // minus one since it's inclusive

        $random = '';
        for ($i = 0; $i < $length; ++$i) {
            $random .= $pool[rand(0, $max)];
        }

        return $random;
    }
}

if (!function_exists('xml_to_array')) {
    /**
     * convert xml to array in a simple way (not recursive)
     *
     * @param SimpleXMLElement|string $xml
     * @return array
     */
    function xml_to_array($xml)
    {
        if (is_string($xml)) {
            $xml = simplexml_load_string($xml);
        }

        return array_map('trim', (array)$xml);
    }
}