<?
class user {
    use error_reporting;
    /*Блок статичных переменных класса, необходимых для подключения к БД объектов класса*/
    private static $db_host = SERVER_ADDR;
    private static $db_user = 'root';
    private static $db_password = '';
    private static $db_name = 'db';
    /*Блок уникальных переменных объектов класса*/
    private $connection = NULL;
    private $id = '';
    private $login = '';
    private $hash_password = '';
    private $role = 'guest';

    public function __construct(bool $use_cookie = false) //При создании экземпляра класса, сразу же создает соединение с БД
    { 
        $this->connection = @mysqli_connect(SELF::$db_host, SELF::$db_user, SELF::$db_password, SELF::$db_name);
        if (!$this->is_connected()) {
            $try_to_recreate = recreate_db(SELF::$db_host, SELF::$db_user, SELF::$db_password, SELF::$db_name); //Используем стороннюю функцию для создания БД по шаблону.
            
            if (!$try_to_recreate['result']) {
                $this->get_error(0, $try_to_recreate['log']);
                return false;
            }

            $this->connection = $try_to_recreate['value']; 
        }

        if ($use_cookie) 
            if (!$this->get_cookie())
                return false;

        return true;
    }

    public function get_(string $feature) //Геттер для получения свойств
    {   
        $temp = new user();

        if ((!array_key_exists($feature, get_object_vars($temp))) or ($feature == "connection"))
            return NULL;

        return $this->$feature;
    }
    
    public function is_connected() //Проверка наличия соединения экземпляра класса с БД
    {
        $this->last_error = false; //Так как во всех последующих функциях будет вызываться этот метод, лишь тут обнуляется состояние ошибки

        if (!$this->connection) 
           return false;
        
        return true;
    }

    private function clear_info() //Приводит все значения объекта класса, кроме connection, к значениям по умолчанию
    {    
        $temp = new user();
        $current_connection = $this->connection;

        foreach (get_object_vars($temp) as $feature=>$value)
            $this->$feature = $temp->$feature;

        $this->connection = $current_connection;

        return true;
    }

    private function get_info(string $login = NULL, string $hash_password = NULL) //Собирает все данные пользователя из БД
    {    
        if ($login == NULL) $login = $this->login;
        if ($hash_password == NULL) $hash_password = $this->hash_password;

        $this->clear_info();

        $query = "SELECT * FROM `users` WHERE `login`='$login' and `hash_password`='$hash_password' LIMIT 1;";

        if (!$query_result = @mysqli_query($this->connection, $query)) {
            $this->get_error(1,'Неудачный запрос к базе данных!');
            return false;
        }
        
        $result = mysqli_fetch_assoc($query_result);
        if (empty($result))
            return false;

        foreach ($result as $feature=>$value) //Все данные об пользователе заносятся в него
            $this->$feature = $value;
        
        return true;
    }   

    private function set_cookie() //Устанавливает объект пользователя в cookie
    {
        if (!setcookie(COOKIE_TOKEN, serialize(['login'=>$this->login, 'hash_password'=>$this->hash_password]), 0, '/')) {
            $this->get_error(4,'Не удалось записать cookie!');
            return false;
        }

        return true;
    }

    private function get_cookie() //Забирает из cookie объект пользователя, если такой есть
    {
        if ((!$temp = @unserialize($_COOKIE[COOKIE_TOKEN])) or (!array_key_exists('login',$temp)) or (!array_key_exists('hash_password',$temp))) {
            $this->get_error(4,'Не удалось записать cookie!');
            return false;
        }

        if (!$this->is_exist($temp['login'], $temp['hash_password'])) {
            $this->get_error(3,'Неправильный логин или пароль!');
            return false;
        }

        if (!$this->get_info($temp['login'], $temp['hash_password']))
            return false;

        return true;
    }
    
    private function clear_cookie() //Очищает cookie
    {
        if (!setcookie(COOKIE_TOKEN, '', 0, '/')) {
            $this->get_error(4,'Не удалось записать cookie!');
            return false;
        }

        return true;
    }

    public function is_exist(string $login = NULL, string $hash_password = NULL) //Проверяет, существует ли пользователь в БД
    {        
        if ($login == NULL) {
            $login = $this->login;
            $hash_password = $this->hash_password;
        }

        if (!empty($hash_password)) 
            $query = "SELECT EXISTS(SELECT `id` FROM `users` WHERE `login`='$login' LIMIT 1);"; 
        else 
            $query = "SELECT EXISTS(SELECT `id` FROM `users` WHERE `login`='$login' and `hash_password`='$hash_password' LIMIT 1);";

        $query_result = @mysqli_query($this->connection, $query);
        if (!$query_result) {
            $this->get_error(1,'Неудачный запрос к базе данных!');
            return false;
        }

        $result = mysqli_fetch_all($query_result, MYSQLI_NUM);
        if (empty($result[0][0]))
            return false;
        
        return true;
    }

    public function try_login(string $new_login, string $new_password) //Стирает текущий объект, cookie, осуществляет попытку входа с последующим получением всех данных объекта и сохранением их в cookie
    {
        $this->clear_cookie();
        
        $hash_password = md5($new_password);

        if (!$this->is_exist($new_login, $hash_password)) {
            $this->get_error(3,'Неправильный логин или пароль!');
            return false;
        }

        if (!$this->get_info($new_login, $hash_password))
            return false;

        $this->set_cookie();
        return true;
    }

    public function try_register(array $features) //Осуществляет попытку регистрации, а затем авторизации по этим данным
    {
        $this->clear_cookie();

        if ($this->is_exist($features['login'])) {
            $this->get_error(2,'Пользователь с таким логином уже существует!');
            return false;
        }

        $hash_password = md5($features['password']);

        $query = "INSERT INTO `users` (`id`, `login`, `hash_password`, `role`) VALUES (NULL, '".$features['login']."', '$hash_password', 'guest');";
        $query_result = @mysqli_query($this->connection, $query);

        if (!$query_result) {
            $this->get_error(1,'Неудачный запрос к базе данных!');
            return false;
        }

        if (!$this->get_info($features['login'], $hash_password))
            return false;

        $this->set_cookie();
        return true;
    }
    public function try_logout() //Стирает текущий объект, cookie
    {
        $this->clear_cookie();
        $this->clear_info();

        return true;
    }  
}
?>