<?php

namespace Riimu\Kit\CSRF\Source;

/**
 * Looks for the token in the $_POST global array variable.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2014, Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class PostSource implements TokenSource
{
    /**
     * Name of the input field used to send the csrf token in forms.
     * @var string
     */
    private $fieldName;

    /**
     * Creates a new instance of PostSource.
     * @param string $fieldName Name of the token field in $_POST
     */
    public function __construct($fieldName = 'csrf_token')
    {
        $this->fieldName = $fieldName;
    }

    public function getRequestToken()
    {
        if (!isset($_POST[$this->fieldName])) {
            return false;
        }

        return $_POST[$this->fieldName];
    }
}
