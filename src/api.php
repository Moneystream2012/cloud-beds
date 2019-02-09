<?php declare(strict_types=1);

/**
 * Class Api
 */
class Api {

    /**
     * @var mysqli
     */
    private $mysqli;

    /** @var array */
    private $request;

    /** @var string */
    private $cmd;

    /** @var array */
    private $response;

    /** @var string */
    private $error;

    const
        DATE_FORMAT = "Y-m-d",
        RESPONSE_FAIL_DB = 0,
        RESPONSE_FAIL_API = 0,
        RESPONSE_SUCCESS = 1;

    /**
     * Api constructor.
     * @param $request
     */
    public function __construct($request) {
        $this->request = $request;
        $this->cmd = $this->getPostVar('cmd');
    }

    public function addOne(int $number): int {
        return $number + 1;
    }

    /**
     * Connect to database, check is .env credential correct
     * @return bool
     */
    public function connect(): bool {
        $this->mysqli = mysqli_connect('db', getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'), 'testdb');

        if ($errno = $this->mysqli->connect_errno) {
            $this->response = [
                'result'  => self::RESPONSE_FAIL_DB,
                'message' => sprintf("Can't connect to db: %s\n, need server restart", $this->mysqli->connect_error),
            ];
        }

        return !$errno;
    }

    /**
     * Proceed API command
     */
    public function run(): void {
        switch ($this->cmd) {
            case 'truncateDb':
                $this->response = [
                    'result'  => (bool) $this->mysqli->query('TRUNCATE TABLE intervals')
                ];
                break;

            case 'removeById':
                $this->response = [
                    'result'  => (bool) $this->mysqli->query(
                        'DELETE FROM intervals WHERE id=' . $this->getPostVar('id')
                    )
                ];
                break;

            // if we edit interval - its equal to remove old AND add new one
            case 'edit':
            case 'add':
                // Prepare all queries data for add new interval
                // after that re-read table
                list($inserts, $removed, $updates) = $this->prepareIntervals();

                if ($this->cmd === 'edit') {
                    $removed[] = $this->getIntervalByKey('old');
                }

                $this->addIntervals($inserts);
                $this->removeIntervals($removed);
                $this->updateIntervals($updates);

                if ($this->error) {
                    $this->response = [
                        'result'  => self::RESPONSE_FAIL_DB,
                        'message' => $this->error
                    ];
                    break;
                }

            case 'index':
                $this->response = [
                    'result' => self::RESPONSE_SUCCESS,
                    'data'   => $this->getIntervals(),
                ];
                break;

            default:
                $this->response = [
                    'result'  => self::RESPONSE_FAIL_API,
                    'message' => 'Undefined command received to API'
                ];
        }
    }

    /**
     * @return array
     */
    protected function prepareIntervals(): array {
        $new = $this->getIntervalByKey('new');
        $intervals = $this->getCachedIntervals();
        return $this->compareInterval($intervals, $new);
    }

    /**\
     * @param string $key
     * @return stdClass
     */
    protected function getIntervalByKey($key): stdClass {
        parse_str($this->getPostVar($key), $data);
        return StdInterval::set($data['date_start'], $data['date_end'], (float) $data['price'], (int) $data['id']);
    }

    /**
     * @return stdClass
     */
    protected function getOldInterval(): stdClass {
        parse_str($this->getPostVar('new'), $new);
        return StdInterval::set($new['date_start'], $new['date_end'], (float) $new['price']);
    }

    /**
     * @return stdClass[]
     */
    protected function getCachedIntervals(): array {
        return array_map(
            function ($interval) {
                return StdInterval::set(
                    $interval->date_start, $interval->date_end, (float) $interval->price, (int) $interval->id
                );
            },
            (array) json_decode($this->getPostVar('intervals'))
        );
    }

        /**
     * Return subset of intervals for [Insert, Remove(s), Update(s)]
     * @param stdClass[] $intervals
     * @param stdClass $added
     * @return array
     */
    protected function compareInterval(array $intervals, stdClass $added): array {
        $inserts = [];
        $removes = [];
        $updates = [];

        foreach ($intervals as $interval) {

            // Will proceed only crossing/ overlapped Intervals
            if ($this->isOverlapped($interval, $added)) {

                // Check is Interval can be merged with Added_interval
                if ($this->canBeMerged($interval, $added)) {
                    $removes[] = $interval;
                    $added = $this->mergeIntervals($interval, $added);

                // Check is Interval fully inside Added_interval - remove him
                } elseif ($this->isCompletelyOverlapped($interval, $added)) {
                    $removes[] = $interval;

                // Check is Interval break apart by Added_interval
                } elseif ($this->isBreakApartExisting($interval, $added)) {
                    list($updates[], $inserts[]) = $this->breakApart($interval, $added);

                // Check is Added_interval early that proceed one
                } elseif ($this->isAddedEarly($interval, $added)) {
                    $updates[] = $this->updateIfEarly($interval, $added);

                // Otherwise Added_interval later that proceed one
                } else {
                    $updates[] = $this->updateIfLater($interval, $added);
                }
            }
        }

        $inserts[] = $added;
        return [$inserts, $removes, $updates];
    }

    /**
     * @param stdClass $interval
     * @param stdClass $added
     * @return bool
     */
    protected function isOverlapped($interval, $added): bool {
        return !($this->isEarly($interval, $added) || $this->isLater($interval, $added));
    }

    /**
     * @param stdClass $interval
     * @param stdClass $added
     * @return bool
     */
    protected function isEarly($interval, $added): bool {
        return ((strtotime($interval->dateStart) > strtotime($added->dateStart)) &&
            (strtotime($interval->dateStart) > strtotime($added->dateEnd)));
    }

    /**
     * @param stdClass $interval
     * @param stdClass $added
     * @return bool
     */
    protected function isLater($interval, $added): bool {
        return ((strtotime($interval->dateEnd) < strtotime($added->dateStart)) &&
            (strtotime($interval->dateEnd) < strtotime($added->dateEnd)));
    }

    /**
     * @param stdClass $i
     * @param stdClass $added
     * @return bool
     */
    protected function canBeMerged(stdClass $i, stdClass $added): bool {
        return $this->isOverlapped($i, $added) && ($i->price === $added->price);
    }

    /**
     * @param stdClass $i
     * @param stdClass $added
     * @return bool
     */
    protected function isCompletelyOverlapped(stdClass $i, stdClass $added): bool {
        return (strtotime($i->dateStart) >= strtotime($added->dateStart)) &&
            (strtotime($i->dateEnd) <= strtotime($added->dateEnd));
    }

    /**
     * @param stdClass $i
     * @param stdClass $added
     * @return bool
     */
    protected function isBreakApartExisting(stdClass $i, stdClass $added): bool {
        return (strtotime($i->dateStart) < strtotime($added->dateStart)) &&
            (strtotime($i->dateEnd) > strtotime($added->dateEnd));
    }

    /**
     * @param stdClass $i
     * @param stdClass $added
     * @return bool
     */
    protected function isAddedEarly(stdClass $i, stdClass $added): bool {
        return (strtotime($i->dateStart) <= strtotime($added->dateEnd)) &&
            (strtotime($i->dateEnd) > strtotime($added->dateEnd));
    }

    /**
     * @param stdClass $interval
     * @param stdClass $added
     * @return stdClass
     */
    protected function mergeIntervals($interval, $added): stdClass {
        return StdInterval::set(
            $this->minDate($interval->dateStart, $added->dateStart),
            $this->maxDate($interval->dateEnd, $added->dateEnd),
            (float) $added->price
        );
    }

    /**
     * Break apart interval, return [1 Update, 1 insert]
     * @param stdClass $interval
     * @param stdClass $added
     * @return stdClass[]
     */
    protected function breakApart($interval, $added): array {
        return [
            StdInterval::set(
                $interval->dateStart,
                date(self::DATE_FORMAT, strtotime($added->dateStart . "-1 day")),
                $interval->price,
                $interval->id
            ),
            StdInterval::set(
                date(self::DATE_FORMAT, strtotime($added->dateEnd . "+1 day")),
                $interval->dateEnd,
                $interval->price
            ),
        ];
    }

    /**
     * Added interval early - update from date next-day-to last of added
     * @param stdClass $interval
     * @param stdClass $added
     * @return stdClass
     */
    protected function updateIfEarly($interval, $added): stdClass {
        return StdInterval::set(
            date(self::DATE_FORMAT, strtotime($added->dateEnd . "+1 day")),
            $interval->dateEnd,
            $interval->price,
            $interval->id
        );
    }

    /**
     * Added interval later - update till date prev-day-from first of added
     * @param stdClass $interval
     * @param stdClass $added
     * @return stdClass
     */
    protected function updateIfLater($interval, $added): stdClass {
        return StdInterval::set(
            $interval->dateStart,
            date(self::DATE_FORMAT, strtotime($added->dateStart . "-1 day")),
            $interval->price,
            $interval->id
        );
    }

    /**
     * @param string $d1
     * @param string $d2
     * @return string
     */
    protected function minDate(string $d1, string $d2): string {
        return strtotime($d1) < strtotime($d2) ? $d1 : $d2;
    }

    /**
     * @param string $d1
     * @param string $d2
     * @return string
     */
    protected function maxDate(string $d1, string $d2): string {
        return strtotime($d1) > strtotime($d2) ? $d1 : $d2;
    }

    /**
     * @param stdClass[] $inserts
     */
    protected function addIntervals(array $inserts): void {
        if ($inserts) {
            foreach ($inserts as $insert) {
                $query = "INSERT INTO intervals(date_start, date_end, price) VALUES " . StdInterval::getSqlValues($insert);
                if (!$this->executeQuery($query)) {
                    break;
                }
            }
        }
    }

    /**
     * @param stdClass[] $removes
     */
    protected function removeIntervals(array $removes): void {
        if (!$this->error && $removes) {
            $removeIds = array_map(
                function ($interval) {return $interval->id;},
                $removes
            );

            $query = "DELETE FROM intervals WHERE id IN(" . implode(',', $removeIds) . ")";
            $this->executeQuery($query);
        }
    }

    /**
     * @FIXME later do throw prepared statement or INSERT-ON-DUPLICATE-KEY-UPDATE
     * @param stdClass[] $updates
     */
    protected function updateIntervals(array $updates): void {
        if (!$this->error && $updates) {
            foreach ($updates as $interval) {
                $query = "UPDATE intervals 
                  SET date_start='{$interval->dateStart}', date_end='{$interval->dateEnd}', price={$interval->price} 
                  WHERE id = {$interval->id}";

                if (!$this->executeQuery($query)) {
                    break;
                }
            }
        }
    }

    /**
     * @param string $query
     * @return bool
     */
    protected function executeQuery(string $query): bool {
        $result = !$this->mysqli->query($query);
        if ($result) {
            $this->error = "Can't execute $query due: " . mysqli_error($this->mysqli);
        }
        return $result;
    }

    /**
     * Fetch all data intervals from db, cast mysqli_result as array
     * @return array
     */
    protected function getIntervals(): array {
        $data = $this->mysqli->query("SELECT * FROM intervals ORDER BY date_start ASC");

        $intervals = [];
        foreach ($data as $interval) {
            $intervals[] = $interval;
        }
        return $intervals;
    }

    /**
     * Get _POST variable with validated key
     * @param string $key
     * @return string|null
     */
    protected function getPostVar(string $key): ?string {
        return array_key_exists($key, $_POST) ? $_POST[$key] : null;
    }

    /**
     * Sending response
     */
    public function sendResponse(): void {
        /* Close db connection */
        mysqli_close($this->mysqli);

        /** Send AJAX response */
        die(json_encode($this->response));
    }
}

/**
 * Class StdInterval
 */
class StdInterval {

    /**
     * @param string $dateStart
     * @param string $dateEnd
     * @param float $price
     * @param int|null $id
     * @return stdClass
     */
    public static function set(string $dateStart, string $dateEnd, float $price, ?int $id=null): stdClass {
        $obj = new stdClass;
        $obj->dateStart = $dateStart;
        $obj->dateEnd = $dateEnd;
        $obj->price = $price;
        $obj->id = $id ? : null;

        return $obj;
    }

    /**
     * @param stdClass $obj
     * @param bool $withId
     * @return string
     */
    public static function getSqlValues(stdClass $obj, bool $withId=false): string {
        return "(" .
            ($withId ? "{$obj->dateStart}," : "") .
            "'{$obj->dateStart}', '{$obj->dateEnd}', {$obj->price})";
    }
}

    $api = new Api($_POST);

    if ($api->connect()) {
        // Route & proceed api command
        $api->run();
    }

    $api->sendResponse();

