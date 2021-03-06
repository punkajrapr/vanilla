<?php
/**
 * Gdn_Pluggable
 *
 * @author Mark O'Sullivan <markm@vanillaforums.com>
 * @copyright 2009-2017 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package Core
 * @since 2.0
 */

/**
 * Event Framework: Pluggable
 *
 * The Pluggable class is extended by other classes to enable the plugins
 * and the custom event model in plugins. Any class that extends this class
 * has the ability to throw custom events at any time, which can then be
 * handled by plugins.
 *
 * @abstract
 */
abstract class Gdn_Pluggable {

    /**
     * @var string The name of the class that has been instantiated. Typically this will be
     * a class that has extended this class.
     */
    protected $ClassName;

    /**
     * @var array Any arguments that should be passed into a plugin's event handler.
     * Typically these are variables that were defined before the event fired
     * and were local to the method being executed. It is an associative array
     * of Argument_Name => Argument_Value pairs. The EventArguments can be
     * accessed (and changed) in the plugin since the plugin has full
     * access to the object throwing the event (see FireEvent() below).
     */
    public $EventArguments;

    /**
     * @var array When any events are handled by plugins, the return values from those
     * method are saved in $this->Returns array as $EventHandlerName =>
     * array($PluginName => $ReturnValue) pairs.
     * Note: Method overrides and direct method call return values are NOT saved
     * in this array; they are returned as normal.
     *  Example: To get the return value a plugin called "TestPlugin" that
     *  attaches to an "ExampleController->Render()" method using the magic
     *  "Before" event, you would reference it like so:
     *  $ReturnVal = $Sender->Returns['ExampleController_BeforeRender_Handler']['TestPlugin'];
     */
    public $Returns = array();


    /**
     * @var enumerator An enumerator indicating what type of handler the method being called is.
     * Options are:
     *  HANDLER_TYPE_NORMAL: Standard call to a method on the object (DEFAULT).
     *  HANDLER_TYPE_OVERRIDE: Call to a method override.
     *  HANDLER_TYPE_NEW: Call to a new object method.
     */
    public $HandlerType;

    /**
     * @var string In some cases it may be desirable to fire an event from a different class
     * than is currently available via $this. If this variable is set, it should
     * contain the name of the class that the next event will fire off.
     */
    public $FireAs;

    /**
     * The public constructor of the class. If any extender of this class
     * overrides this one, parent::__construct(); should be called to ensure
     * interoperability.
     */
    public function __construct() {
        $this->ClassName = get_class($this);
        $this->EventArguments = array();
        $this->Returns = array();
        $this->HandlerType = HANDLER_TYPE_NORMAL;
    }

    /**
     *
     *
     * @param string $PluginName
     * @param string $HandlerName
     * @return
     */
    public function getReturn($PluginName, $HandlerName) {
        return $this->Returns[strtolower($HandlerName)][strtolower($PluginName)];
    }

    /**
     * Fire the next event off a custom parent class
     *
     * @param mixed $Options Either the parent class, or an option array
     */
    public function fireAs($Options) {
        if (!is_array($Options)) {
            $Options = array('FireClass' => $Options);
        }

        if (array_key_exists('FireClass', $Options)) {
            $this->FireAs = val('FireClass', $Options);
        }

        return $this;
    }

    /**
     * Fires an event to be dealt with by plugins. Plugins can create
     * custom methods to handle the event simply by naming their handler method
     * appropriately. The convention is:
     *  public function SenderClassName_EventName_Handler($Sender) {}
     *
     * @param string $EventName The name of the event being fired.
     */
    public function fireEvent($EventName, $Arguments = null) {
        if (!$this->ClassName) {
            $RealClassName = get_class($this);
            throw new Exception("Event fired from pluggable class '{$RealClassName}', but Gdn_Pluggable::__construct() was never called.");
        }

        $FireClass = !is_null($this->FireAs) ? $this->FireAs : $this->ClassName;
        $this->FireAs = null;

        // Apply inline arguments to EventArguments
        if (is_array($Arguments)) {
            $this->EventArguments = array_merge($this->EventArguments, $Arguments);
        }

        // Look to the PluginManager to see if there are related event handlers and call them
        return Gdn::pluginManager()->callEventHandlers($this, $FireClass, $EventName);
    }

    /**
     * Used to extend any method.
     *
     * There are two types of extended method calls:
     *  1. Declared: The method was declared with the lowercase "x" prefix and called without it.
     *     ie. Declaration: public function xMethodName() {}
     *         Call: $Object->MethodName();
     *
     *  2. Called: The method was declared without the lowercase "x" prefix and called with it.
     *     ie. Declaration: public function MethodName() {}
     *         Call: $Object->xMethodName();
     *
     * Note: Plugins will always refer to the method name without the "x"
     * regardless of the type. So, $ReferenceMethodName is declared below without
     * the "x".
     *
     *
     * @param string $MethodName
     * @param array $Arguments
     * @return mixed
     *
     */
    public function __call($MethodName, $Arguments) {
        // Define a return variable.
        $Return = false;

        // We removed the SliceProvider class, which Pluggable previously extended.
        // If any of these methods are called, send out an error.
        $sliceProviderMethods = ['enableSlicing', 'slice', 'addSliceAsset', 'renderSliceConfig'];

        if (in_array($MethodName, $sliceProviderMethods)) {
            $message = 'Slicing has been removed from Gdn_Pluggable.';
            $message .= ' Try using the functionality provided by "js-form" instead.';
            throw new Exception($message);
        }


        // Was this method declared, or called?
        if (substr($MethodName, 0, 1) == 'x') {
            // Declared
            $ActualMethodName = substr($MethodName, 1); // Remove the x prefix
            $ReferenceMethodName = $ActualMethodName; // No x prefix
        } else {
            // Called
            $ActualMethodName = 'x'.$MethodName; // Add the x prefix
            $ReferenceMethodName = $MethodName; // No x prefix
        }

        // Make sure that $ActualMethodName exists before continuing:
        if (!method_exists($this, $ActualMethodName)) {
            // Make sure that a plugin is not handling the call
            if (!Gdn::pluginManager()->hasNewMethod($this->ClassName, $ReferenceMethodName)) {
                trigger_error(ErrorMessage('The "'.$this->ClassName.'" object does not have a "'.$ActualMethodName.'" method.', $this->ClassName, $ActualMethodName), E_USER_ERROR);
            }
        }

        // Make sure the arguments get passed in the same way whether firing a custom event or a magic one.
        $this->EventArguments = $Arguments;

        // Call the "Before" event handlers
        Gdn::pluginManager()->callEventHandlers($this, $this->ClassName, $ReferenceMethodName, 'Before');

        // Call this object's method
        if (Gdn::pluginManager()->hasMethodOverride($this->ClassName, $ReferenceMethodName)) {
            // The method has been overridden
            $this->HandlerType = HANDLER_TYPE_OVERRIDE;
            $Return = Gdn::pluginManager()->callMethodOverride($this, $this->ClassName, $ReferenceMethodName);
        } elseif (Gdn::pluginManager()->hasNewMethod($this->ClassName, $ReferenceMethodName)) {
            $this->HandlerType = HANDLER_TYPE_NEW;
            $Return = Gdn::pluginManager()->callNewMethod($this, $this->ClassName, $ReferenceMethodName);
        } else {
            // The method has not been overridden
            $Count = count($Arguments);
            if ($Count == 0) {
                $Return = $this->$ActualMethodName();
            } elseif ($Count == 1) {
                $Return = $this->$ActualMethodName($Arguments[0]);
            } elseif ($Count == 2) {
                $Return = $this->$ActualMethodName($Arguments[0], $Arguments[1]);
            } elseif ($Count == 3) {
                $Return = $this->$ActualMethodName($Arguments[0], $Arguments[1], $Arguments[2]);
            } elseif ($Count == 4) {
                $Return = $this->$ActualMethodName($Arguments[0], $Arguments[1], $Arguments[2], $Arguments[3]);
            } else {
                $Return = $this->$ActualMethodName($Arguments[0], $Arguments[1], $Arguments[2], $Arguments[3], $Arguments[4]);
            }
        }

        // Call the "After" event handlers
        Gdn::pluginManager()->callEventHandlers($this, $this->ClassName, $ReferenceMethodName, 'After');

        return $Return;
    }
}
