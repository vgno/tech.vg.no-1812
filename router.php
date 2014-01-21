<?php
if (isset($_SERVER['HTTP_X_COLLECT_COVERAGE']) && isset($_SERVER['HTTP_X_TEST_SESSION_ID'])) {
    require __DIR__ . '/vendor/autoload.php';

    // Output code coverage stored in the .cov files
    $coverageDir = sys_get_temp_dir() . '/behat-coverage';

    if (!is_dir($coverageDir)) {
        // Create tmp dir
        mkdir($coverageDir);
    }

    $files = new FilesystemIterator(
        $coverageDir,
        FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS
    );
    $data = array();
    $suffix = $_SERVER['HTTP_X_TEST_SESSION_ID'] . '.cov';

    foreach ($files as $filename) {
        if (!preg_match('/' . preg_quote($suffix, '/') . '$/', $filename)) {
            continue;
        }

        $content = unserialize(file_get_contents($filename));
        unlink($filename);

        foreach ($content as $file => $lines) {
            if (is_file($file)) {
                if (!isset($data[$file])) {
                    $data[$file] = $lines;
                } else {
                    foreach ($lines as $line => $flag) {
                        if (!isset($data[$file][$line]) || $flag > $data[$file][$line]) {
                            $data[$file][$line] = $flag;
                        }
                    }
                }
            }
        }
    }

    echo serialize($data);
    exit;
}

if (isset($_SERVER['HTTP_X_ENABLE_COVERAGE']) && isset($_SERVER['HTTP_X_TEST_SESSION_ID']) && extension_loaded('xdebug')) {
    // Register a shutdown function that stops code coverage and stores the coverage of the current
    // request
    register_shutdown_function(function() {
        $data = xdebug_get_code_coverage();
        xdebug_stop_code_coverage();

        $coverageDir = sys_get_temp_dir() . '/behat-coverage';

        if (is_dir($coverageDir) || mkdir($coverageDir, 0775, true)) {
            $filename = sprintf(
                '%s/%s.%s.cov',
                $coverageDir,
                md5(uniqid('', true)),
                $_SERVER['HTTP_X_TEST_SESSION_ID']
            );

            file_put_contents($filename, serialize($data));
        }
    });

    // Start code coverage
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
}

// Return false from the router to serve the requested file as is
return false;
