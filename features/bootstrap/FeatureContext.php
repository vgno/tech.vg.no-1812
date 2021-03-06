<?php
use Behat\Behat\Context\BehatContext,
    Behat\Behat\Event\SuiteEvent,
    Guzzle\Http\Client;

require 'PHP/CodeCoverage.php';
require 'PHP/CodeCoverage/Filter.php';
require 'PHP/CodeCoverage/Report/HTML.php';

/**
 * Features context
 */
class FeatureContext extends BehatContext {
    /**
     * Pid for the web server
     *
     * @var int
     */
    private static $pid;

    /**
     * A session ID for this test session
     *
     * @var string
     */
    private static $testSessionId;

    /**
     * Guzzle client used to make requests against the httpd
     *
     * @var Client
     */
    protected $client;

    /**
     * Class constructor
     *
     * @param array $parameters Context parameters
     */
    public function __construct(array $parameters) {
        $this->params = $parameters;
        $this->client = new Client($this->params['url']);

        $defaultHeaders = array(
            'X-Test-Session-Id' => self::$testSessionId,
        );

        if ($this->params['enableCodeCoverage']) {
            $defaultHeaders['X-Enable-Coverage'] = 1;
        }

        $this->client->setDefaultHeaders($defaultHeaders);
    }

    /**
     * Start up the web server
     *
     * @BeforeSuite
     */
    public static function setUp(SuiteEvent $event) {
        // Fetch config
        $params = $event->getContextParameters();
        $url = parse_url($params['url']);
        $port = !empty($url['port']) ? $url['port'] : 80;

        if (self::canConnectToHttpd($url['host'], $port)) {
            throw new RuntimeException('Something is already running on ' . $params['url'] . '. Aborting tests.');
        }

        // Try to start the web server
        self::$pid = self::startBuiltInHttpd(
            $url['host'],
            $port,
            $params['documentRoot'],
            $params['router']
        );

        if (!self::$pid) {
            throw new RuntimeException('Could not start the web server');
        }

        $start = microtime(true);
        $connected = false;

        // Try to connect until the time spent exceeds the timeout specified in the configuration
        while (microtime(true) - $start <= (int) $params['timeout']) {
            if (self::canConnectToHttpd($url['host'], $port)) {
                $connected = true;
                break;
            }
        }

        if (!$connected) {
            self::killProcess(self::$pid);
            throw new RuntimeException(
                sprintf(
                    'Could not connect to the web server within the given timeframe (%d second(s))',
                    $params['timeout']
                )
            );
        }

        self::$testSessionId = uniqid('behat-coverage-', true);
    }

    /**
     * Kill the httpd process if it has been started when the tests have finished
     *
     * @AfterSuite
     */
    public static function tearDown(SuiteEvent $event) {
        $parameters = $event->getContextParameters();

        if ($parameters['enableCodeCoverage']) {
            $client = new Client($parameters['url']);
            $response = $client->get('/', array(
                'X-Enable-Coverage' => 1,
                'X-Test-Session-Id' => self::$testSessionId,
                'X-Collect-Coverage' => 1,
            ))->send();

            $data = unserialize((string) $response->getBody());

            $filter = new PHP_CodeCoverage_Filter();

            foreach ($parameters['whitelist'] as $dir) {
                $filter->addDirectoryToWhitelist($dir);
            }

            $coverage = new PHP_CodeCoverage(null, $filter);
            $coverage->append($data, 'behat-suite');

            $report = new PHP_CodeCoverage_Report_HTML();
            $report->process($coverage, $parameters['coveragePath']);
        }

        if (self::$pid) {
            self::killProcess(self::$pid);
        }
    }

    /**
     * Kill a process
     *
     * @param int $pid
     */
    private static function killProcess($pid) {
        exec('kill ' . (int) $pid);
    }

    /**
     * See if we can connect to the httpd
     *
     * @param string $host The hostname to connect to
     * @param int $port The port to use
     * @return boolean
     */
    private static function canConnectToHttpd($host, $port) {
        // Disable error handler for now
        set_error_handler(function() { return true; });

        // Try to open a connection
        $sp = fsockopen($host, $port);

        // Restore the handler
        restore_error_handler();

        if ($sp === false) {
            return false;
        }

        fclose($sp);

        return true;
    }

    /**
     * Start the built in httpd
     *
     * @param string $host The hostname to use
     * @param int $port The port to use
     * @param string $documentRoot The document root
     * @param string $router Path to an optional router
     * @return int Returns the PID of the httpd
     */
    private static function startBuiltInHttpd($host, $port, $documentRoot, $router = null) {
        // Build the command
        $command = sprintf('php -S %s:%d -t %s %s >/dev/null 2>&1 & echo $!',
                            $host,
                            $port,
                            $documentRoot,
                            $router);

        $output = array();
        exec($command, $output);

        return (int) $output[0];
    }
}
