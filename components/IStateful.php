<?php

namespace netis\fsm\components;

interface IStateful
{

    const SCENARIO = 'transition';

    /**
     * Returns all possible state transitions as an array of items like:
     *   array('state'=>StateTransition, 'targets'=>StateTransition[]).
     * @return array
     */
    public function getTransitionsGroupedBySource();

    /**
     * Returns all possible state transitions as an array of items like:
     *   array('state'=>StateTransition, 'sources'=>StateTransition[]).
     * @return array
     */
    public function getTransitionsGroupedByTarget();

    /**
     * Returns the name of the state attribute.
     * @return mixed
     */
    public function getStateAttributeName();

    /**
     * This method is called to verify that a transition is enabled. Using this method could be simpler
     * than attaching bizRules to auth items assigned to state transitions.
     * @param $targetState mixed
     * @return boolean
     */
    public function isTransitionAllowed($targetState);

    /**
     * Similar to save(), it validates and then saves attributes marked as safe in the IStateful::SCENARIO.
     * Besides that it could also log the change and/or raise events.
     *
     * @param $oldAttributes array
     * @param $data array
     * @return boolean
     */
    public function performTransition($oldAttributes, $data);

    /**
     * Modifies validators list obtained through getValidators() adding rules for a specific transition.
     * @param $targetState mixed
     * @return 
     */
    public function setTransitionRules($targetState);
}
