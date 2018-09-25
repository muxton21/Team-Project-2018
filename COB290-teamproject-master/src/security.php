<?php // Author: Christopher Stevens

use Silex\Provider\SecurityServiceProvider;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Doctrine\DBAL\Connection;

$app->register(new SecurityServiceProvider(), array(
    "security.firewalls" => array(
        "login" => array(
            "pattern" => "^/login$"
        ),
        "app" => array(
            "pattern" => "^.*$",
            "form" => array(
                "login_path" => "/login",
                "check_path" => "/api/login"
            ),
            "logout" => array(
                "logout_path" => "/logout",
                "invalidate_session" => true
            ),
            "users" => function() use ($app) {
                return new MakeItAllUserProvider($app["db"]);
            }
        )
    )
));

class MakeItAllUserProvider implements UserProviderInterface
{
    private $conn;

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Loads the user for the given username.
     *
     * This method must throw UsernameNotFoundException if the user is not
     * found.
     *
     * @param string $email The email address
     *
     * @return UserInterface
     *
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByUsername($email)
    {
        $stmt = $this->conn->executeQuery("SELECT email, password FROM Operator WHERE email = ?", array(strtolower($email)));

        if (!$user = $stmt->fetch()) {
            throw new UsernameNotFoundException("No account registered with the email address ".$email);
        }

        return new User($user["email"], $user["password"], array("User"), true, true, true, true);
    }

    /**
     * Refreshes the user.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param UserInterface $user the user object to update
     *
     * @return UserInterface
     *
     * @throws UnsupportedUserException if the user is not supported
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        return $this->loadUserByUsername($user->getUsername());
    }

    /**
     * Whether this provider supports the given user class.
     *
     * @param string $class
     *
     * @return bool
     */
    public function supportsClass($class)
    {
        return $class === "Symfony\Component\Security\Core\User\User";
    }
}
