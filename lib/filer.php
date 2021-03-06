<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class HashAnalysis {
    private $prefix;
    private $hash;
    private $binary;

    function __construct($hash) {
        $len = strlen($hash);
        if ($len === 37
            && strcasecmp(substr($hash, 0, 5), "sha2-") == 0) {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 20) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 69
                   && strcasecmp(substr($hash, 0, 5), "sha2-") == 0
                   && ctype_xdigit(substr($hash, 5))) {
            $this->prefix = "sha2-";
            $this->hash = substr($hash, 5);
            $this->binary = false;
        } else if ($len === 40
                   && ctype_xdigit($hash)) {
            $this->prefix = "";
            $this->hash = $hash;
            $this->binary = false;
        } else if ($len === 32) {
            $this->prefix = "sha2-";
            $this->hash = $hash;
            $this->binary = true;
        } else if ($len === 64
                   && ctype_xdigit($hash)) {
            $this->prefix = "sha2-";
            $this->hash = $hash;
            $this->binary = false;
        } else if ($len === 25
                   && strcasecmp(substr($hash, 0, 5), "sha1-") == 0) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = true;
        } else if ($len === 45
                   && strcasecmp(substr($hash, 0, 5), "sha1-") == 0
                   && ctype_xdigit(substr($hash, 5))) {
            $this->prefix = "";
            $this->hash = substr($hash, 5);
            $this->binary = false;
        } else {
            if ($hash === "")
                $this->prefix = "";
            else if ($hash === "sha256")
                $this->prefix = "sha2-";
            else
                $this->prefix = "xxx-";
        }
    }
    static function make_known_algorithm($algo) {
        $ha = new HashAnalysis("");
        if ($algo === "sha1") {
            $ha->prefix = "";
        } else {
            $ha->prefix = "sha2-";
        }
        return $ha;
    }

    function ok() {
        return $this->hash !== null;
    }
    function known_algorithm() {
        return $this->prefix !== "xxx-";
    }
    function algorithm() {
        if ($this->prefix === "sha2-") {
            return "sha256";
        } else if ($this->prefix === "") {
            return "sha1";
        } else {
            return "xxx";
        }
    }
    function prefix() {
        return $this->prefix;
    }
    function text() {
        return $this->prefix . ($this->binary ? bin2hex($this->hash) : strtolower($this->hash));
    }
    function binary() {
        return $this->prefix . ($this->binary ? $this->hash : hex2bin($this->hash));
    }
    function text_data() {
        return $this->binary ? bin2hex($this->hash) : strtolower($this->hash);
    }
}

class Filer {
    static public $tempdir;
    static public $tempcounter = 0;
    static public $no_touch;

    // download
    static function download_file($filename, $no_accel = false) {
        global $Conf, $zlib_output_compression;
        // if docstoreAccelRedirect, output X-Accel-Redirect header
        if (($dar = $Conf->opt("docstoreAccelRedirect"))
            && ($ds = $Conf->opt("docstore"))
            && !$no_accel) {
            if (!str_ends_with($ds, "/")) {
                $ds .= "/";
            }
            if (str_starts_with($filename, $ds)
                && ($tail = substr($filename, strlen($ds)))
                && preg_match('/\A[^\/]+/', $tail)) {
                if (!str_ends_with($dar, "/")) {
                    $dar .= "/";
                }
                header("X-Accel-Redirect: $dar$tail");
                return;
            }
        }
        // write length header, flush output buffers
        if (!$zlib_output_compression) {
            header("Content-Length: " . filesize($filename));
        }
        flush();
        while (@ob_end_flush()) {
            // do nothing
        }
        // read file directly to output
        readfile($filename);
    }
    static function multidownload($doc, $downloadname = null, $opts = null) {
        global $Now, $zlib_output_compression;
        if (is_array($doc) && count($doc) == 1) {
            $doc = $doc[0];
            $downloadname = null;
        } else if (is_array($doc)) {
            $z = new ZipDocument($downloadname);
            foreach ($doc as $d) {
                $z->add($d);
            }
            return $z->download();
        }

        if (!$doc->ensure_content()) {
            $error_html = "Don’t know how to download.";
            if ($doc->error && isset($doc->error_html)) {
                $error_html = $doc->error_html;
            }
            return (object) ["error" => true, "error_html" => $error_html];
        }
        if ($doc->size == 0 && !$doc->ensure_size()) {
            return (object) ["error" => true, "error_html" => "Empty file."];
        }

        // Print paper
        header("Content-Type: " . Mimetype::type_with_charset($doc->mimetype));
        if (is_bool($opts)) {
            $attachment = $opts;
        } else if (is_array($opts) && isset($opts["attachment"])) {
            $attachment = $opts["attachment"];
        } else {
            $attachment = !Mimetype::disposition_inline($doc->mimetype);
        }
        if (!$downloadname) {
            $downloadname = $doc->filename;
            if (($slash = strrpos($downloadname, "/")) !== false)
                $downloadname = substr($downloadname, $slash + 1);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($downloadname));
        if (is_array($opts) && get($opts, "cacheable")) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", $Now + 315576000) . " GMT");
        }
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
        if ($doc->has_hash()) {
            header("ETag: \"" . $doc->text_hash() . "\"");
        }
        if (($path = $doc->available_content_file())) {
            self::download_file($path, get($doc, "no_cache") || get($doc, "no_accel"));
        } else {
            if (!$zlib_output_compression)
                header("Content-Length: " . strlen($doc->content));
            echo $doc->content;
        }
        return (object) ["error" => false];
    }

    // hash helpers
    static function hash_as_text($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->text() : false;
    }
    static function hash_as_binary($hash) {
        assert(is_string($hash));
        $ha = new HashAnalysis($hash);
        return $ha->ok() ? $ha->binary() : false;
    }
    static function sha1_hash_as_text($str) {
        $ha = new HashAnalysis($str);
        return $ha->ok() && $ha->prefix() === "" ? $ha->text() : false;
    }

    // filestore path functions
    const FPATH_EXISTS = 1;
    const FPATH_MKDIR = 2;
    static function docstore_path(DocumentInfo $doc, $flags = 0) {
        global $Now;
        if ($doc->error || !($pattern = $doc->conf->docstore())) {
            return null;
        }
        if (!($path = self::_expand_docstore($pattern, $doc, true))) {
            return null;
        }
        if ($flags & self::FPATH_EXISTS) {
            if (!is_readable($path)) {
                // clean up presence of old files saved w/o extension
                $g = self::_expand_docstore($pattern, $doc, false);
                if ($path && $g !== $path && is_readable($g)) {
                    if (!@rename($g, $path)) {
                        $path = $g;
                    }
                } else {
                    return null;
                }
            }
            if (filemtime($path) < $Now - 172800 && !self::$no_touch) {
                @touch($path, $Now);
            }
        }
        if (($flags & self::FPATH_MKDIR)
            && !self::prepare_docstore(self::docstore_fixed_prefix($pattern), $path)) {
            return $doc->add_error_html("File system storage cannot be initialized.", true);
        } else {
            return $path;
        }
    }

    static function docstore_fixed_prefix($pattern) {
        if ($pattern == "") {
            return $pattern;
        }
        $prefix = "";
        while (($pos = strpos($pattern, "%")) !== false) {
            if ($pos == strlen($pattern) - 1) {
                break;
            } else if ($pattern[$pos + 1] === "%") {
                $prefix .= substr($pattern, 0, $pos + 1);
                $pattern = substr($pattern, $pos + 2);
            } else {
                $prefix .= substr($pattern, 0, $pos);
                if (($rslash = strrpos($prefix, "/")) !== false) {
                    return substr($prefix, 0, $rslash + 1);
                } else {
                    return "";
                }
            }
        }
        $prefix .= $pattern;
        if ($prefix[strlen($prefix) - 1] !== "/") {
            $prefix .= "/";
        }
        return $prefix;
    }

    static private function prepare_docstore($parent, $path) {
        if (!self::_make_fpath_parents($parent, $path))
            return false;
        // Ensure an .htaccess file exists, even if someone else made the
        // filestore directory
        $htaccess = "$parent/.htaccess";
        if (!is_file($htaccess)
            && @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n") === false) {
            @unlink($htaccess);
            return false;
        }
        return true;
    }

    static function docstore_tmpdir($pattern) {
        if (is_object($pattern)) {
            $pattern = $pattern->opt("docstore");
        }
        if ($pattern
            && ($prefix = self::docstore_fixed_prefix($pattern))) {
            $tmpdir = $prefix . "tmp/";
            if (self::prepare_docstore($tmpdir, $tmpdir)) {
                return $tmpdir;
            }
        }
        return false;
    }

    static private function _expand_docstore($pattern, DocumentInfo $doc, $extension) {
        $x = "";
        $hash = false;
        while (preg_match('/\A(.*?)%(\d*)([%hxHjaA])(.*)\z/', $pattern, $m)) {
            $x .= $m[1];
            list($fwidth, $fn, $pattern) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                $x .= "%";
            } else if ($fn === "x") {
                if ($extension) {
                    $x .= Mimetype::extension($doc->mimetype);
                }
            } else {
                if ($hash === false
                    && ($hash = $doc->text_hash()) === false) {
                    return false;
                }
                if ($fn === "h" && $fwidth === "") {
                    $x .= $hash;
                } else if ($fn === "a") {
                    $x .= $doc->hash_algorithm();
                } else if ($fn === "A") {
                    $x .= $doc->hash_algorithm_prefix();
                } else if ($fn === "j") {
                    $x .= substr($hash, 0, strlen($hash) === 40 ? 2 : 3);
                } else {
                    $h = $hash;
                    if (strlen($h) !== 40) {
                        $pos = strpos($h, "-") + 1;
                        if ($fn === "h") {
                            $x .= substr($h, 0, $pos);
                        }
                        $h = substr($h, $pos);
                    }
                    if ($fwidth === "") {
                        $x .= $h;
                    } else {
                        $x .= substr($h, 0, intval($fwidth));
                    }
                }
            }
        }
        return $x . $pattern;
    }

    static private function _make_fpath_parents($fdir, $fpath) {
        $lastslash = strrpos($fpath, "/");
        $container = substr($fpath, 0, $lastslash);
        while (str_ends_with($container, "/")) {
            $container = substr($container, 0, strlen($container) - 1);
        }
        if (!is_dir($container)) {
            if (strlen($container) < strlen($fdir)
                || !($parent = self::_make_fpath_parents($fdir, $container))
                || !@mkdir($container, 0770)) {
                return false;
            } else if (!@chmod($container, 02770 & fileperms($parent))) {
                @rmdir($container);
                return false;
            }
        }
        return $container;
    }
}
