<?php
require_once 'autoload.php';
require_once 'utils/Utils.php';
require_once 'utils/Database.php';
require_once 'utils/Validator.php';
require_once 'model/Field.php';

require_once 'model/ClientTable.php';
require_once 'model/DadTable.php';
require_once 'model/ClientHasDadTable.php';

class Controller
{
    private $action;
    private $db;
    private $twig;

    private $client_table;
    private $dad_table;
    private $client_has_dad_table;

    public function __construct()
    {
        // setup connection
        Utils::setupConnection();

        // load template engine
        $loader = new Twig\Loader\FilesystemLoader('./view');
        $this->twig = new Twig\Environment($loader);
        $this->twig->addGlobal('session', $_SESSION);

        // connect to database
        $this->connectToDatabase();
        $this->client_table = new ClientTable($this->db->getDB());
        $this->dad_table = new DadTable($this->db->getDB());
        $this->client_has_dad_table = new ClientHasDadTable($this->db->getDB());

        // get action
        $this->action = Utils::getAction();
    }

    private function connectToDatabase()
    {
        $this->db = new Database();
        if (!$this->db->isConnected()) {
            $error_message = $this->db->getErrorMessage();
            echo $this->twig->load('errors/database.twig')->render(['error_message' => $error_message]);
            exit();
        }
    }

    public function invoke()
    {
        match ($this->action) {
            'Home' => $this->showHomePage(),
            'Returning Home' => $this->showHomePage('returning'),
            'Welcome Home' => $this->showHomePage('welcome'),
            'Logout Home' => $this->showHomePage('logout'),
            'Show Registration' => $this->showRegistrationPage(),
            'Register' => $this->registerClient(),
            'Show Login' => $this->showLoginPage(),
            'Login' => $this->loginClient(),
            'Logout' => $this->logoutUser(),
            'Show Dad Selection' => $this->showDadSelection(),
            'Rent This Dad' => $this->showAppointmentPage(),
            'Set Appointment' => $this->setAppointment(),
            'My Dads' => $this->showRentedDadsPage(),
            'Unauthorized' => $this->showUnauthorizedErrorPage(),
            'Authorized Error' => $this->showAuthorizedErrorPage(),
            default => $this->showHomePage()
        };
    }


    private function showHomePage($version = '')
    {
        $first_name = null;

        if (isset($_SESSION['username'])) {
            $first_name = $this->client_table->getFirstNameViaUsername($_SESSION['username']);
        }

        echo $this->twig->load('home.twig')->render(['version' => $version, 'first_name' => $first_name]);
    }

    private function showRegistrationPage(
        $username = null,
        $password = null,
        $confirm_password = null,
        $first_name = null,
        $last_name = null,
        $email = null
    ) {
        if (isset($_SESSION['is_valid_user']) && $_SESSION['is_valid_user']) {
            header('Location: .?action=Authorized Error');
            return;
        }

        echo $this->twig->load('registration.twig')->render(['fields' => [
            $username ?? new Field('username'),
            $password ?? new Field('password', 'password'),
            $confirm_password ?? new Field('confirm_password', 'password'),
            $first_name ?? new Field('first_name'),
            $last_name ?? new Field('last_name'),
            $email ?? new Field('email')
        ]]);
    }

    private function registerClient()
    {
        $username = new Field('username');
        $username->value = filter_input(INPUT_POST, 'username');
        Validator::required($username);
        Validator::properLength($username);
        if ($this->client_table->clientExists($username->value) && !Field::hasError($username))
            $username->error = 'Username already exists.';

        $password = new Field('password', 'password');
        $password->value = filter_input(INPUT_POST, 'password');
        Validator::required($password);
        Validator::properLength($password, 8, 20);
        Validator::matchPattern($password, '/^(?=.*[[:upper:]])(?=.*[[:lower:]]).*$/', 'Must contain an uppercase and lowercase letter.');
        Validator::matchPattern($password, '/^(?=.*[[:digit:]]).*$/', 'Must contain a number.');
        Validator::matchPattern($password, '/^(?=.*[!@#$%^&*]).*$/', 'Must contain a symbol (!@#$%^&*).');

        $confirm_password = new Field('confirm_password', 'password');
        $confirm_password->value = filter_input(INPUT_POST, 'confirm_password');
        Validator::checkConfirmPassword($confirm_password, $password);

        $first_name = new Field('first_name');
        $first_name->value = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
        Validator::required($first_name);
        Validator::properLength($first_name);

        $last_name = new Field('last_name');
        $last_name->value = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
        Validator::required($last_name);
        Validator::properLength($last_name);

        $email = new Field('email');
        $email->value = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        Validator::required($email);
        Validator::properLength($email, 1, 50);
        Validator::checkEmail($email);

        if (Validator::allValid([$username, $password, $confirm_password, $first_name, $last_name, $email])) {

            $this->client_table->addClient($username->value, $password->value, $first_name->value, $last_name->value, $email->value);

            $_SESSION['is_valid_user'] = true;
            $_SESSION['username'] = $username->value;

            header("Location: .?action=Welcome Home");
            return;
        }

        $password->value = '';
        $confirm_password->value = '';

        $this->showRegistrationPage(
            $username,
            $password,
            $confirm_password,
            $first_name,
            $last_name,
            $email
        );
    }

    private function showLoginPage($username = null, $password = null, $login_error = '')
    {
        if (isset($_SESSION['is_valid_user']) && $_SESSION['is_valid_user']) {
            header('Location: .?action=Authorized Error');
            return;
        }

        echo $this->twig->load('login.twig')->render(['fields' => [
            $username ?? new Field('username'),
            $password ?? new Field('password', 'password')
        ], 'login_error' => $login_error]);
    }

    private function loginClient()
    {
        $username = new Field('username');
        $username->value = filter_input(INPUT_POST, 'username');
        Validator::required($username);

        $password = new Field('password', 'password');
        $password->value = filter_input(INPUT_POST, 'password');
        Validator::required($password);

        $login_error = '';
        if (!$this->client_table->isValidLogin($username->value, $password->value))
            $login_error = 'Username or password was not recognized';

        if (Validator::allValid([$username, $password]) && $login_error === '') {

            $_SESSION['is_valid_user'] = true;
            $_SESSION['username'] = $username->value;

            header("Location: .?action=Returning Home");
            return;
        }

        $this->showLoginPage($username, $password, $login_error);
    }

    private function logoutUser()
    {
        $_SESSION = [];
        $this->twig->addGlobal('session', $_SESSION);

        session_destroy();

        header("Location: .?action=Logout Home");
    }

    private function showDadSelection()
    {
        $dads = $this->dad_table->getAllDads();

        echo $this->twig->load('dad_selection.twig')->render(['dads' => $dads]);
    }

    private function showAppointmentPage($start_time_field = null, $end_time_field = null)
    {
        if (!isset($_SESSION['is_valid_user'])) {
            header("Location: .?action=Unauthorized");
            return;
        }

        $dad_id = filter_input(INPUT_POST, 'dad_id');
        $dad = $this->dad_table->getDad($dad_id);

        echo $this->twig->load('appointment.twig')->render([
            'fields' => [
                $start_time_field ?? new Field('start_time', 'datetime-local'),
                $end_time_field ?? new Field('end_time', 'datetime-local')
            ], 'dad' => $dad,
            'hidden' => [['name' => 'dad_id', 'value' => $dad_id]]
        ]);
    }

    private function setAppointment()
    {
        if (!isset($_SESSION['is_valid_user'])) {
            header('Location: .?action=Unauthorized');
            return;
        }

        $start_time_field = new Field('start_time', 'datetime-local');
        $start_time_field->value = filter_input(INPUT_POST, 'start_time');
        Validator::required($start_time_field);

        $end_time_field = new Field('end_time', 'datetime-local');
        $end_time_field->value = filter_input(INPUT_POST, 'end_time');
        Validator::required($end_time_field);

        if (Validator::allValid([$start_time_field, $end_time_field])) {
            $client_id = $this->client_table->getIDViaUsername($_SESSION['username']);
            $dad_id = filter_input(INPUT_POST, 'dad_id');
            $start_time = Utils::formatTime($start_time_field->value);
            $end_time = Utils::formatTime($end_time_field->value);

            $this->client_has_dad_table->setAppointment($client_id, $dad_id, $start_time, $end_time);
    
            $this->showRentedDadsPage();
            return;
        }

        $this->showAppointmentPage($start_time_field, $end_time_field);
    }

    private function showRentedDadsPage()
    {
        if (!isset($_SESSION) || !isset($_SESSION['is_valid_user'])) {
            header('Location: .?action=Unauthorized');
            return;
        }

        $client_id = $this->client_table->getIDViaUsername($_SESSION['username']);

        if (!$client_id) {
            echo 'Something is wrong with your account?';
            $this->showHomePage();
            return;
        }

        $dads = $this->client_table->getClientDads($client_id);

        echo $this->twig->load('rented_dads.twig')->render(['dads' => $dads]);
    }

    private function showUnauthorizedErrorPage()
    {
        echo $this->twig->load('errors/unauthorized.twig')->render();
    }

    private function showAuthorizedErrorPage()
    {
        echo $this->twig->load('errors/authorized.twig')->render();
    }
}
