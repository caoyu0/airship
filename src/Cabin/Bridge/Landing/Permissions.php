<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Landing;

require_once __DIR__.'/gear.php';

class Permissions extends AdminOnly
{
    private $perms;

    public function airshipLand()
    {
        parent::airshipLand();
        $this->perms = $this->blueprint('Permissions');
        $this->users = $this->blueprint('UserAccounts');
    }

    /**
     * @route crew/permissions/{string}
     *
     * @param string $cabin
     */
    public function cabinSubmenu(string $cabin)
    {
        if (!\in_array($cabin, $this->getCabinNames())) {
            \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions');
        }
        $post = $this->post();
        if (!empty($post)) {
            $this->processCabinSubmenu($cabin, $post);
        }
        $this->lens(
            'perms/cabin_submenu',
            [
                'cabin' =>
                    $cabin,
                'actions' =>
                    $this->perms->getActions($cabin),
                'contexts' =>
                    $this->perms->getContexts($cabin)
            ]
        );
    }

    /**
     * @route crew/permissions/{string}/action/{id}
     *
     * @param string $cabin
     * @param string $actionId
     */
    public function editAction(string $cabin, string $actionId)
    {
        if (!\in_array($cabin, $this->getCabinNames())) {
            \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions');
        }
        $post = $this->post();
        $action = $this->perms->getAction($cabin, $actionId);
        if (empty($action)) {
            \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions/' . $cabin);
        }
        if (!empty($post)) {
            if ($this->perms->saveAction($cabin, $actionId, $post)) {
                \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions/' . $cabin);
            }
        }
        $this->lens(
            'perms/action',
            [
                'action' =>
                    $action
            ]
        );
    }

    /**
     * @route crew/permissions/{string}/context/{id}
     *
     * @param string $cabin
     * @param string $contextId
     */
    public function editContext(string $cabin, string $contextId)
    {
        $contextId += 0;
        if (!\in_array($cabin, $this->getCabinNames())) {
            \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions');
        }

        $context = $this->perms->getContext($contextId, $cabin);
        if (empty($context)) {
            \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions' . $cabin);
        }

        // Handle post data
        $post = $this->post();
        if (!empty($post)) {
            if ($this->perms->saveContext($cabin, $contextId, $post)) {
                \Airship\redirect($this->airship_cabin_prefix . '/crew/permissions/' . $cabin . '/context/' . $contextId);
            }
        }

        // Okay,
        $actions = $this->perms->getActionNames($cabin);
        $groupPerms = $this->perms->buildGroupTree(
            $cabin,
            $contextId,
            $actions
        );
        $userPerms = $this->perms->buildUserList(
            $cabin,
            $contextId,
            $actions
        );
        $users = [];
        foreach ($userPerms as $userid => $userPerm) {
            $users[$userid] = $this->users->getUserAccount($userid, true);
            unset($users[$userid]['password']);
        }
        $this->lens(
            'perms/context',
            [
                'actions' =>
                    $actions,
                'cabin' =>
                    $cabin,
                'context' =>
                    $context,
                'permissions' =>
                    $groupPerms,
                'userperms' =>
                    $userPerms,
                'users' =>
                    $users
            ]
        );

    }

    /**
     * @route crew/permissions
     */
    public function index()
    {
        $this->lens(
            'perms/index',
            [
                'cabins' =>
                    $this->getCabinNames()
            ]
        );
    }

    protected function processCabinSubmenu(string $cabin, array $post): bool
    {
        if (!empty($post['create_context']) && !empty($post['new_context'])) {
            return $this->perms->createContext($cabin, $post['new_context']);
        } elseif (!empty($post['create_action']) && !empty($post['new_action'])) {
            return $this->perms->createAction($cabin, $post['new_action']);
        }
        return false;
    }
}