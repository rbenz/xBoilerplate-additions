<?php
/**
 * CW_MySQL - Simplified database access class to provide quick, easy and secure queries.
 *
 * The MySQL class is intended to provide clients with a simplified set of SQL queries that should cover 80% of use
 * cases. It also provides more power via the query() method, where a custom query can be supplied - however, it is
 * expected that this method is only used as a last resort. For Centralwayers, using it will mean you're asked to
 * justify *why* you are using it!
 *
 * The standard SQL operations are available:
 *  select()
 *  update()
 *  insert()
 *
 * The following additional operations are available:
 *  selectRow() - selects a single row, returning the first result
 *
 * The following operations are *not* available:
 *  delete() - hard-deleting is not recommended, consider using a soft delete (i.e. a boolean column called isDeleted)
 *
 * More information can be found on github's wiki page here:
 *  https://github.com/centralway/xBoilerplate-additions/wiki/MySQL
 *
 * @author Oliver Tupman <oliver.tupman@centralway.com>
 * @version 0.1
 *
 */

class CW_MySQL
{
    //TODO: currently all exceptions are Exception; need to create a MySQLException class once all error scenarios are found
    /**
     * the static instance of the class
     * @var CW_MySQL
     */
    protected static $_object = null;
    /**
     *
     * The static mysqli instance that the class uses
     *
     * @var mysqli
     */
    protected static $_db = null;

    /** Optionally used to signal an empty where clause */
    const NO_WHERE = null;
    /** Optionally used to signal an empty order instruction  */
    const NO_ORDER = null;
    /** Optionally used to signal that there is no limit set on the query */
    const NO_LIMIT = null;
    /** The default class instantiated to return results with */
    const STANDARD_CLASS = 'stdClass';

    /** Sort operator: ascending */
    const OP_ASC = 'ASC';
    /** Sort operator: descending */
    const OP_DESC = 'DESC';

    private static $VALID_OPERATORS = array(
        '>', '<', '=', '!='
    );


    protected function __construct() {
        //$config = xBoilerplate::getConfig()->db;
        //self::$_db = new mysqli($config['host'], $config['username'], $config['password'], $config['db']);
        self::$_db = new mysqli('localhost', 'root', '', 'xBoilerplate_additions');
    }

    /**
     * Performs a raw-query upon the database.
     *
     * This method gives you full control over your access to the database, however it is unprotected and not
     * recommended for 80% of applications and queries.
     *
     * It is left to the calling code to ensure that the data in the query is safe to execute. In addition, this
     * method does not return an array of results but the raw mysqli_result object.
     *
     * If you are a Centralwayer, prepare to justify your use of this method.
     *
     * @param string $query the query to execute
     * @throws Exception in the event of any SQL exceptions occur
     * @return mysqli_result
     */
    public function query($query) {
        $statement = self::$_db->prepare($query);
        if($statement === false) {
            throw self::createQueryException('Error preparing query for execution', self::$_db, $query);
        }

        if(!$statement->execute()) {
            throw self::createQueryException('Error executing query', self::$_db, $query);
        }

        return $statement->get_result();
    }

    /**
     * Obtains the static instance of the CW_MySQL class.
     *
     * @return CW_MySQL
     */
    public static function getInstance() {
        if (!self::$_object) {
            self::$_object = new self();
        }
        return self::$_object;
    }

    /**
     * Builds the PrivateQueryParameters for the supplied associative array
     *
     * This takes a query parameter array and converts it into the set of 3
     * arrays.
     *
     * Incoming data is an associative array with key: column name, value: field value
     *
     * This method also determines the target type of the field based on the type of the
     * value passed in for each field.
     *
     * @param array|\associative $parameterArray associative array of parameters
     * @return PrivateQueryParameters
     */
    protected function createParameters(array $parameterArray) {
        $whereClause = new PrivateQueryParameters();
        if($parameterArray == self::NO_WHERE) {
            return $whereClause; // Early exit if no where clause
        }

        foreach($parameterArray as $columnName => $columnValue) {
            $name = trim($columnName);
            $needsEquals = stripos($name, ' ') === false;
            if($needsEquals) {
                $name .= ' =';
            }
            $name .= ' ? ';
            $targetType = $this->getType($columnValue);
            $value = $this->convertValue($columnValue, $targetType);
            $whereClause->addClause($name, $targetType, $value);
        }

        return $whereClause;
    }

    //TODO: This needs to handle type hinting
    private function convertValue($value, $targetType) {
        if($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        else {
            return $value; // No conversion necessary
        }
    }

    /**
     * Selects data from the specified table, always returning an array of the retrieve results.
     *
     * By default this method will return an array of objects of type stdClass; each object will
     * have a property based on the column names returned as part of the column list.
     *
     * All results are retrieved in one call, therefore it is the responsibility of the caller to
     * perform any necessary limiting (either by limiting or by filtering with a where clause).
     *
     * Simple call - no where, order or limit:
     * SELECT firstname, lastname FROM people;
     * ->  select(array('firstname', 'lastname'), 'people');
     *
     * Note: SELECT * is not permitted; it will thrown an exception. You *must* specify the columns
     * you wish to retrieve.
     *
     * Filtering call - where with simple implicit 'equals' operators
     *
     * If you are performing a simple where with just equals operators (i.e. ID = 2) then you can
     * simply pass in the column name as the key for each part of the where clause:
     *
     * SELECT firstname, lastname FROM people WHERE firstname = 'fred';
     * ->  select(array('firstname', 'lastname'), 'people', array('firstname' => 'fred'));
     *
     * Filtering call - where with operators
     *
     * To perform a more complex query with operators (greater than >, not equal to !=) you must pass
     * them in as part of the key of the where clause.
     *
     * SELECT firstname, age FROM people WHERE age > 11;
     * -> select(array('firstname', 'lastname'), 'people', array('age >' => 11));
     *
     * Ordered call - SORT BY firstname
     *
     * SELECT firstname, lastname FROM people SORT BY firstname ASC
     * -> select(array('firstname', 'lastname'), 'people', null, array('firstname' => 'ASC'));
     *
     * Limited call - LIMIT 1, 10
     *
     * SELECT firstname FROM people LIMIT 1;
     * -> select(array('firstname'), 'people', null, null, 1);
     *
     * SELECT firstname FROM people LIMIT 10, 20;
     * -> select(array('firstname'), 'people', null, null, array(10, 20));
     *
     * Custom objects - have the select() function return custom objects instead of stdClass
     *
     * By default the select() method returns an array of stdClass instances. Pass in a class name
     * and the method will instantiate that class and assign the values to that instead.
     *
     * $people = select(array('firstname', 'lastname'), 'people', null, null, null, 'person');
     *
     *
     * @param $columns the columns to retrieve data from
     * @param $table the table to retrieve the data from
     * @param array $where optional; where clause with which to filter data, key: column name, value: value
     * @param array $order optional; associative array of fields to order by and in which direction
     * @param array $limit optional; pass in a integer to limit TO, pass in an 2-element array to limit FROM and TO
     * @param string $className; optional, pass in a class name for the function to instantiate
     * @throws Exception in the event of an issue TODO: issue-specific exceptions
     * @return array of objects found; each object will be of stdClass unless $className is passed
     */
    public function select($columns, $table, $where = self::NO_WHERE, $order = self::NO_ORDER, $limit = self::NO_LIMIT,
                           $className = self::STANDARD_CLASS)
    {
        $columnFragment = implode(', ', $columns);
        $containsSelectAsterisk = stripos($columnFragment, '*') !== false;
        if($containsSelectAsterisk) {
            throw new Exception('Select * is not allowed');
        }

        $whereClause = $this->createParameters($where != null ? $where : array());
        $orderClauses = $this->buildOrderClauses($order != null ? $order : array());

        $query = 'SELECT ' . $columnFragment . ' ';
        $query.= 'FROM ' . $table . ' ';
        $query.= $this->generateWhere($whereClause, $query);

        if(sizeof($orderClauses) > 0) {
            $query.= ' ORDER BY ' . implode(', ', $orderClauses);
        }
        if($limit != null) {
            $query.= ' LIMIT ' . $this->createLimitSql($limit);
        }

        $statement = self::$_db->prepare($query);
        if($statement === false) {
            throw self::createQueryException('Could not prepare query', self::$_db, $query);
        }

        if(!$whereClause->isEmpty()) {
            $values = array();
            foreach($whereClause->getValues() as $value) {
                $values[] = &$value;
            }
            $typeList = $whereClause->getTypeList();
            $functionParams = array_merge(array(&$typeList), $values);

            call_user_func_array(array($statement, 'bind_param'), $functionParams);
        }

        return $this->executeSelectStatement($statement, $query, $className);
    }

    /**
     * Builds the order clauses from the incoming data, if any are present
     *
     * @param $order the order to build from
     * @return array containing the build SQL order clauses
     * @throws InvalidArgumentException in the event that any of the incoming data is invalid (i.e. ASK instead of ASC)
     */
    private function buildOrderClauses($order)
    {
        $orderClauses = array();

        if ($order != null) {
            array_walk($order, function($val, $key) use (&$orderClauses)
            {
                $val = strtoupper(trim($val));
                if ($val != CW_MySQL::OP_ASC && $val != CW_MySQL::OP_DESC) {
                    throw new InvalidArgumentException('Order must either be ASC or DESC. Order column: ' . $key);
                }
                $orderClauses[] = $key . ' ' . $val;
            });
            return $orderClauses;
        }
        return $orderClauses;
    }

    /**
     * Selects one single row based on the clause supplied.
     *
     * Essentially a simplification of calling SELECT [columns] FROM [table] WHERE [where] LIMIT 1
     *
     * Best used with ID-based to retrieve one column. This method MUST be called with a where clause.
     *
     * See select() for more information about each parameter
     *
     * @param array $columns the columns to retrieve
     * @param $table the table to retrieve from
     * @param array $where the where clause to filter the results by
     * @param string $className optional; the name of the class to instantiate, if not passed, stdClass is used
     *
     * @throws InvalidArgumentException if any of the supplied arguments are incorrect
     *
     * @return object if found, the object from the database; otherwise, null
     */
    public function selectRow(array $columns, $table, array $where, $className = self::STANDARD_CLASS) {
        if(sizeof($where) == 0) {
            throw new InvalidArgumentException('Where clause cannot be an empty array');
        }
        else if(trim(strtolower($where[0])) == 'true' || trim($where[0]) == '1') {
            throw new InvalidArgumentException('Where clause must not be true or 1; specify the row to retrieve.');
        }
        $resultArray = $this->select($columns, $table, $where, self::NO_ORDER, 1);
        if(sizeof($resultArray) > 0) {
            return $resultArray[0];
        }
        else {
            return null;
        }
    }

    /**
     * Creates the SQL for the parameters to a LIMIT clause
     *
     * @param $limit the incoming limit arguments
     * @return string the string ready to be appended to a LIMIT clause
     * @throws InvalidArgumentException in the event that the incoming limit arguments are not correct
     */
    private function createLimitSql($limit)
    {
        $limitValue = '';
        if (is_numeric($limit)) {
            $limitValue = $limit;
            return $limitValue;
        }
        else if (is_array($limit) && sizeof($limit) == 2) {
            $limitValue = $limit[0] . ',' . $limit[1];
            return $limitValue;
        }
        else {
            throw new InvalidArgumentException('Limit must either be a single numeric, or a 2-element array of integers');
        }
    }

    /**
     * Generates a 'WHERE x,y,z' clause
     *
     * @param $whereClause there where parameters
     * @return string the string of the where clause, if parameters are present; otherwise, a empty string
     */
    private function generateWhere($whereClause)
    {
        $query = '';
        if (!$whereClause->isEmpty()) {
            $query .= 'WHERE ';
            $query .= implode(' AND ', $whereClause->getConditions());
            return $query;
        }
        return $query;
    }

    /**
     * Gets the appropriate type string for the supplied value that is an instance of an object.
     *
     * This method can be overridden by deriving classes to provide custom-type handling
     *
     * @param $value the value to get the type of; must be an object
     * @param $typeHint the hint TODO: really needed?
     * @return string the single-character type value for the object
     * @throws Exception if the type of the value is not supported
     */
    protected function getObjectType($value, $typeHint) {
        if($value instanceof DateTime) {
            return 's';
        }
        else {
            throw new Exception('Unsupported type of ' . get_class($value));
        }
    }

    /**
     * Obtains the mysqli type character for the supplied value, using any hinting provided
     *
     * @param $value the value to get the type of
     * @param string $typeHint name of the type that the value is
     * @return string the single character representation of the type
     * @throws Exception in the event the type of the value is unsupported
     */
    protected function getType($value, $typeHint = null) {
        switch(gettype($value)) {
            case 'integer':
                return 'i';
            case 'double':
                return 'd';
            case 'string':
                return 's';
            case 'object':
               return $this->getObjectType($value, $typeHint);
            default:
                throw new Exception('Unsupported type: ' . gettype($value));
        }
    }

    /**
     * executes the statement of the supplied query, retriving rows with the class name
     *
     * @param mysqli_stmt $statement
     * @param $query
     * @param $className
     * @return array
     * @throws Exception
     */
    private function executeSelectStatement(mysqli_stmt $statement, $query, $className)
    {
        if (!$statement->execute()) {
            throw self::createQueryException('Error executing query', $statement, $query);
        }

        $result = $statement->get_result();
        if ($result === false) {
            throw self::createQueryException('Error getting results', $statement, $query);
        }
        $results = array();
        while(($row = $result->fetch_object($className)) != null) {
            $results[] = $row;
        }
        $statement->close();

        return $results;
    }

    /**
     * Simple function that creates an instance of an exception with a message and adds the relevant mysqli error to it
     *
     *
     * @static
     * @param $message the message to be reported
     * @param $dbObject the mysqli object that experienced the error
     * @param $query the query that was/should be executed
     * @return Exception the exception, ready for throwing
     */
    protected static function createQueryException($message, $dbObject, $query) {
        return new Exception($message . ', error: '
            . $dbObject->error
            . ' for query: '
            . $query);
    }


    public function insert($table, array $data) {

    }

    public function delete($table, $where) {

    }



    /**
     * @param $table
     * @param array $updatedData
     * @param $where
     */
    public function update($table, $updatedData, $where) {
//        if($where == null || sizeof($where) == 0) {
//            throw new Exception('Where must not be null or have 0 elements');
//        }
//        else if(array_key_exists('*', $where)
//                || array_key_exists('true', $where)
//                || array_key_exists('1', $where))
//        {
//            throw new Exception('Where may not be * or true');
//        }
//        if($updatedData == null || sizeof($updatedData) == 0) {
//            throw new Exception('Data to update must not be null or have 0 elements');
//        }
//
//        $whereParameters = $this->createParameters($where);
//        $updateParameters = $this->createParameters($updatedData);
//
//        $query = 'UPDATE ' . $table;
//        $query.= ' SET ' . implode(', ', $updateParameters->getConditions());
//        $query.= ' WHERE ' . implode(', ', $whereParameters->getConditions());
//
//        $statement = self::$_db->prepare($query);
//
//        if($statement === false) {
//            throw self::createQueryException('Error preparing update query', self::$_db, $query);
//        }
//
//        $queryValues = array();
//        foreach($updateParameters->getValues() as $value) {
//            $queryValues[] = &$value;
//        }
//        foreach($whereParameters->getValues() as $value) {
//            $queryValues[] = &$value;
//        }
//        $typeList = $updateParameters->getTypeList() . $whereParameters->getTypeList();
//        $functionParams = array_merge(array(&$typeList), $queryValues);
//
//        call_user_func_array(array($statement, 'bind_param'), $functionParams);
//
//        if(!$statement->execute()) {
//            throw self::createQueryException('Error executing update query', $statement, $query);
//        }
//
//        return $statement->affected_rows;
        throw new Exception('Not yet implemented - still working on it');
    }

    public function getLastInsertId() {
        return self::$_db->insert_id;
    }
}

/**
 * Private class; this should never be used outside of the MySQL class.
 *
 * It encapsulates the 3 axises relevant to a successful query:
 *  1. type(list) - mysqli-compatible type list (i.e. sddsssb)
 *  2. values - an array of values
 *  3. conditions - an array of the conditions (i.e. firstname = ?)
 *
 * This class should NEVER be used outside of the MySQL class.
 */
class PrivateQueryParameters {
    private $_typeList;
    private $_values;
    private $_conditions;

    public function __construct() {
        $this->_typeList = '';
        $this->_conditions = array();
        $this->_values = array();
    }

    /**
     * Adds an additional query clause to the parameters
     *
     * @param $condition the condition; must be in the format [fieldname] [operator] ?
     * @param $type the mysqli type of the value
     * @param $value the value
     * @return PrivateQueryParameters this for further building
     */
    public function addClause($condition, $type, $value) {
        $this->_conditions[] = $condition;
        $this->_values[] = $value;
        $this->_typeList .= $type;
        return $this;
    }

    public function getTypeList() {
        return $this->_typeList;
    }

    public function getValues() {
        return $this->_values;
    }

    public function getConditions() {
        return $this->_conditions;
    }

    public function isEmpty() {
        return sizeof($this->_conditions) == 0;
    }
}

