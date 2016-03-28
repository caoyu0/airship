<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Blueprint;

require_once __DIR__.'/gear.php';

class Permissions extends BlueprintGear
{
    const MAX_RECURSE_DEPTH = 100;

    /**
     * Build a hierarchical tree containing all of the groups' permissions
     *
     * @param string $cabin Which cabin does this apply to?
     * @param int $contextId Context IDs (see permissions sql)
     * @param array $actions Actions in Scope [ [id => label], [id2 => label2]  ]
     * @param int $parentid The group whose children we are evaluating
     * @param array $inherited
     * @param int $depth the depth of our recursive search
     * @return array
     */
    public function buildGroupTree(
        string $cabin,
        int $contextId,
        array $actions = [],
        int $parentid = 0,
        array $inherited = [],
        int $depth = 0
    ): array {
        if ($depth > self::MAX_RECURSE_DEPTH) {
            return [];
        }
        if (empty($cabin) || empty($actions) || empty($contextId)) {
            return [];
        }

        // This is the tree we are building...
        $tree = [];

        // Let's grab all the groups with a specific ancestor
        if (empty($parentid)) {
            $groups = $this->db->run(
                \Airship\queryStringRoot('security.permissions.groups_null', $this->db->getDriver())
            );
        } else {
            $groups = $this->db->run(
                \Airship\queryStringRoot('security.permissions.groups_inherits', $this->db->getDriver()),
                $parentid
            );
        }

        // Nothing? Return null
        if (empty($groups)) {
            return [];
        }

        // Let's safely construct a parameter to use to concatenate to the query
        $act_keys = \array_keys($actions);
        $_acts = $this->db->escapeValueSet($act_keys, 'int');

        $baseline = [];
        foreach (\array_values($actions) as $a) {
            $baseline[$a] = false;
        }

        // Now let's build the tree
        foreach ($groups as $grp) {
            $grp['perms'] = $baseline;
            $grp['inherit'] = $inherited;

            // Let's grab the permission data for this particular group
            $qs = \Airship\queryStringRoot(
                'security.permissions.groups_qs',
                $this->db->getDriver(),
                [
                    'actionids' => $_acts
                ]
            );
            $pdata = $this->db->run(
                $qs,
                $cabin,
                $contextId,
                $grp['groupid']
            );
            foreach ($pdata as $perm) {
                $grp['perms'][$perm['label']] = true;
                $grp['inherit'][$perm['label']] = true;
            }

            // Pass onto the next generation
            $grp['children'] = self::buildGroupTree(
                $cabin,
                $contextId,
                $actions,
                $grp['groupid'],
                $grp['inherit'],
                $depth + 1
            );

            // Append this branch
            $tree[] = $grp;
        }

        // With any luck, we now have a hierarchical array to play with in
        // our template.
        return $tree;
    }
    /**
     * Build a multi-context tree; make sure to && the permissions together
     *
     * @param string $cabin
     * @param array $contexts
     * @param array $actions
     * @return array
     */
    public function buildMultiContextGroupTree(
        string $cabin,
        array $contexts = [],
        array $actions = []
    ): array {
        $return = [];
        foreach ($contexts as $ctx) {
            $return[$ctx] = $this->buildGroupTree(
                $cabin,
                $ctx,
                $actions
            );
        }
        return $this->flattenMultiContextTree(
            $return,
            $contexts,
            $actions
        );
    }

    /**
     * Build a multi-context tree; make sure to && the permissions together
     *
     * @param string $cabin
     * @param array $contexts
     * @param array $actions
     * @return array
     */
    public function buildMultiContextUserList(
        string $cabin,
        array $contexts = [],
        array $actions = []
    ): array {
        $return = [];
        foreach ($contexts as $ctx) {
            $return[$ctx] = $this->buildUserList(
                $cabin,
                $ctx,
                $actions
            );
        }
        return $this->flattenMultiContextList(
            $return,
            $contexts,
            $actions
        );
    }

    /**
     * Build a heirarchal tree conataining all of the groups' permissions
     *
     * @param string $cabin Which cabin does this apply to?
     * @param int $contextId Context IDs (see permissions sql)
     * @param array $actions Actions in Scope [ [id => label], [id2 => label2]  ]
     * @return array
     */
    public function buildUserList(
        string $cabin,
        int $contextId,
        array $actions = []
    ): array {
        if (empty($cabin) || empty($actions) || empty($contextId)) {
            return null;
        }
        // This is the tree we are building...
        $users = [];

        // Let's grab all the user IDs with relevant bits
        $userids = $this->db->run(
            \Airship\queryStringRoot(
                'security.permissions.users_list_userids',
                $this->db->getDriver()
            ),
            $contextId
        );
        if (empty($userids)) {
            return [];
        }
        foreach ($userids as $u) {
            $users[] = (int) $u['userid'];
        }

        // Set up a default array
        $baseline = [];
        foreach (\array_values($actions) as $a) {
            $baseline[$a] = false;
        }

        // Now let's set up the permissions list
        $perms = [];
        $label = \Airship\queryStringRoot(
            'security.permissions.users_list_label_contextual',
            $this->db->getDriver()
        );
        foreach ($users as $u) {
            $perms[$u] = $baseline;
            foreach ($this->db->run($label, $contextId, $u) as $p) {
                $perms[$u][$p['label']] = true;
            }
        }
        return $perms;
    }

    /**
     * @param string $cabin
     * @param string $label
     * @return bool
     */
    public function createAction(string $cabin, string $label): bool
    {
        $exists = $this->db->cell(
                'SELECT count(*) FROM airship_perm_actions WHERE cabin = ? AND label = ?',
                $cabin,
                $label
            ) > 0;
        if (!$exists) {
            $this->db->beginTransaction();
            $this->db->insert(
                'airship_perm_actions',
                [
                    'cabin' => $cabin,
                    'label' => $label
                ]
            );
            return $this->db->commit();
        }
        return false;
    }

    /**
     * @param string $cabin
     * @param string $locator
     * @return bool
     */
    public function createContext(string $cabin, string $locator): bool
    {
        $exists = $this->db->cell(
                'SELECT count(*) FROM airship_perm_contexts WHERE cabin = ? AND locator = ?',
                $cabin,
                $locator
            ) > 0;
        if (!$exists) {
            $this->db->beginTransaction();
            if ($locator === '') {
                $locator = '/';
            }
            $this->db->insert(
                'airship_perm_contexts',
                [
                    'cabin' => $cabin,
                    'locator' => $locator
                ]
            );
            return $this->db->commit();
        }
        return false;
    }

    /**
     * @param string $cabin
     * @param string $actionId
     * @return array
     */
    public function getAction(string $cabin, string $actionId): array
    {
        $actions = $this->db->row('SELECT * FROM airship_perm_actions WHERE cabin = ? AND actionid = ?', $cabin, $actionId);
        if (empty($actions)) {
            return [];
        }
        return $actions;
    }

    /**
     * @param string $cabin
     * @return array
     */
    public function getActions(string $cabin): array
    {
        $actions = $this->db->run('SELECT * FROM airship_perm_actions WHERE cabin = ?', $cabin);
        if (empty($actions)) {
            return [];
        }
        return $actions;
    }

    /**
     * @param string $cabin
     * @return array
     */
    public function getActionNames(string $cabin): array
    {
        $return = [];
        $actions = $this->db->run('SELECT * FROM airship_perm_actions WHERE cabin = ?', $cabin);
        foreach ($actions as $act) {
            $return[$act['actionid']] = $act['label'];
        }
        return $return;
    }

    /**
     * Get all contexts for a cabin
     *
     * @param int $contextId
     * @param string $cabin Cabin
     * @return array
     */
    public function getContext(int $contextId, string $cabin = \CABIN_NAME): array
    {
        $context =  $this->db->row(
            'SELECT * FROM airship_perm_contexts WHERE cabin = ? AND contextid = ?',
            $cabin,
            $contextId
        );
        if (empty($context)) {
            return [];
        }
        return $context;
    }

    /**
     * Get all contexts for a cabin
     *
     * @param string $cabin Cabin
     * @return array
     */
    public function getContexts(string $cabin = \CABIN_NAME): array
    {
        $contexts =  $this->db->run(
            'SELECT * FROM airship_perm_contexts WHERE cabin = ? ORDER BY locator ASC',
            $cabin
        );
        if (empty($contexts)) {
            return [];
        }
        return $contexts;
    }

    /**
     * Returns an array with overlapping context IDs -- useful for when
     * contexts are used with regular expressions
     *
     * @param string $uri Context
     * @param string $cabin Cabin
     * @return array
     */
    public function getContextsForURI(
        string $uri = '',
        string $cabin = \CABIN_NAME
    ) {
        if (empty($uri)) {
            $uri = AutoPilot::$path;
        }
        return $this->db->run(
            \Airship\queryStringRoot(
                'security.permissions.get_overlap_with_locator',
                $this->db->getDriver()
            ),
            $cabin,
            $uri
        );
    }

    /**
     * Returns an array with overlapping context IDs -- useful for when
     * contexts are used with regular expressions
     *
     * @param string $uri Context
     * @param string $cabin Cabin
     * @return array
     */
    public function getContextIds(
        string $uri = '',
        string $cabin = \CABIN_NAME
    ) {
        if (empty($uri)) {
            $uri = AutoPilot::$path;
        }
        $ctx = $this->db->col(
            \Airship\queryStringRoot(
                'security.permissions.get_overlap',
                $this->db->getDriver()
            ),
            0,
            $cabin,
            $uri
        );
        if (empty($ctx)) {
            return [];
        }
        return $ctx;
    }

    /**
     * @param int $groupId
     * @param int $contextId
     * @return array
     */
    public function getGroupPerms(int $groupId, int $contextId): array
    {
        $perms = $this->db->col(
            ' SELECT a.label FROM airship_perm_rules r ' .
            ' LEFT JOIN airship_perm_actions a ON r.action = a.actionid ' .
            ' WHERE r.context = ? AND r.groupid = ?',
            0,
            $contextId,
            $groupId
        );
        if (empty($perms)) {
            return [];
        }
        return $perms;
    }

    /**
     * @param int $userId
     * @param int $contextId
     * @return array
     */
    public function getUserPerms(int $userId, int $contextId): array
    {
        $perms = $this->db->col(
            ' SELECT a.label FROM airship_perm_rules r ' .
                ' LEFT JOIN airship_perm_actions a ON r.action = a.actionid ' .
                ' WHERE r.context = ? AND r.userid = ?',
            0,
            $contextId,
            $userId
        );
        if (empty($perms)) {
            return [];
        }
        return $perms;
    }

    /**
     * @param string $cabin
     * @param string $actionId
     * @param array $post
     * @return bool
     */
    public function saveAction(string $cabin, string $actionId, array $post = []): bool
    {
        $this->db->beginTransaction();
        if (!empty($post['label'])) {
            $this->db->update(
                'airship_perm_actions',
                [
                    'label' => $post['label']
                ],
                [
                    'actionid' => $actionId,
                    'cabin' => $cabin
                ]
            );
        }
        return $this->db->commit();
    }

    /**
     * @param string $cabin
     * @param int $contextId
     * @param array $post
     * @return bool
     */
    public function saveContext(string $cabin, int $contextId, array $post): bool
    {
        $actions = $this->getActionNames($cabin);
        $actionIds = \array_flip($actions);

        $post['group_perms'] = $this->permBoolean(
            $post['group_perms'] ?? [],
            $actions
        );
        $post['user_perms'] = $this->permBoolean(
            $post['user_perms'] ?? [],
            $actions
        );

        $groupPerms = $this->getGroupRulesForContextSave($actions, $contextId);
        $userPerms = $this->getUserRulesForContextSave($actions, $contextId);

        \ksort($groupPerms);
        \ksort($post['group_perms']);
        $groupInsert = \Airship\array_multi_diff($post['group_perms'], $groupPerms);
        $groupDelete = \Airship\array_multi_diff($groupPerms, $post['group_perms']);

        \ksort($userPerms);
        \ksort($post['user_perms']);
        $userInsert = \Airship\array_multi_diff($post['user_perms'], $userPerms);
        $userDelete = \Airship\array_multi_diff($userPerms, $post['user_perms']);

        $this->db->beginTransaction();
        // Update the locator
        $this->db->update('airship_perm_contexts', [
            'locator' => $post['context']
        ], [
            'cabin' => $cabin,
            'contextid' => $contextId
        ]);
        foreach ($groupInsert as $group => $inserts) {
            foreach ($inserts as $lbl => $val) {
                if ($val) {
                    $this->db->insert('airship_perm_rules', [
                        'context' => $contextId,
                        'groupid' => $group,
                        'action' => $actionIds[$lbl]
                    ]);
                }
            }
        }
        foreach ($groupDelete as $group => $deletes) {
            foreach ($deletes as $lbl => $val) {
                if ($val) {
                    $this->db->delete('airship_perm_rules', [
                        'context' => $contextId,
                        'groupid' => $group,
                        'action' => $actionIds[$lbl]
                    ]);
                }
            }
        }
        foreach ($userDelete as $user => $deletes) {
            foreach ($deletes as $lbl => $val) {
                if ($val) {
                    $this->db->delete('airship_perm_rules', [
                        'context' => $contextId,
                        'userid' => $user,
                        'action' => $actionIds[$lbl]
                    ]);
                }
            }
        }
        foreach ($userInsert as $user => $inserts) {
            foreach ($inserts as $lbl => $val) {
                if ($val) {
                    $this->db->insert('airship_perm_rules', [
                        'context' => $contextId,
                        'userid' => $user,
                        'action' => $actionIds[$lbl]
                    ]);
                }
            }
        }

        return $this->db->commit();
    }

    /**
     * @param array $return
     * @param array $contexts
     * @param array $actions
     * @return array
     */
    protected function flattenMultiContextTree(
        array $return,
        array $contexts,
        array $actions = []
    ): array {
        $tree = [];
        foreach ($contexts as $c) {
            if (empty($tree)) {
                $tree = $return[$c];
                continue;
            }
            $tree = $this->flattenContextTree($tree, $return[$c], $actions);
        }
        return $tree;
    }

    /**
     * @param array $return
     * @param array $perms
     * @param array $actions
     * @return array
     */
    protected function flattenContextTree(
        array $return,
        array $perms,
        array $actions = []
    ): array {

        foreach ($perms as $i => $per) {
            // Combine with AND logic
            foreach ($actions as $l) {

                // Active permissions
                $return[$i]['perms'][$l] = (
                    ($return[$i]['perms'][$l] ?? false) &&
                    (
                    ($per['perms'][$l] ?? false)
                    )
                );

                // Inherited permissions
                $return[$i]['inherit'][$l] = $return[$i]['inherit'][$l] ?? false;
                $return[$i]['inherit'][$l] = (
                    $return[$i]['inherit'][$l]
                    && (
                        ($per['inherit'][$l] ?? false)
                        && ($return[$i]['inherit'][$l] ?? false)
                    )
                );

            }
            // Recursion!
            if (!empty($per['children'])) {
                $return[$i]['children'] = $this->flattenContextTree(
                    $return[$i]['children'],
                    $per['children'],
                    $actions
                );
            }
        }
        return $return;
    }

    /**
     * @param array $return
     * @param array $contexts
     * @param array $actions
     * @return array
     */
    protected function flattenMultiContextList(
        array $return,
        array $contexts,
        array $actions = []
    ): array {
        $list = [];
        foreach ($contexts as $c) {
            if (empty($list)) {
                $list = $return[$c];
                continue;
            }
            $list = $this->flattenContextList($list, $return[$c], $actions);
        }
        return $list;
    }

    /**
     * @param array $return
     * @param array $perms
     * @param array $actions
     * @return array
     */
    protected function flattenContextList(
        array $return,
        array $perms,
        array $actions = []
    ): array {
        foreach ($return as $usr => $parr) {
            foreach ($actions as $l) {
                $return[$usr][$l] = (
                       ($return[$usr][$l])
                    && ($perms[$usr][$l])
                );
            }
        }
        return $return;
    }

    /**
     * @param array $actions
     * @param int $contextId
     * @return array
     */
    protected function getGroupRulesForContextSave(array $actions, int $contextId): array
    {
        $perms = [];
        $groupIds = $this->db->col(
            'SELECT DISTINCT groupid FROM airship_perm_rules WHERE context = ? AND groupid IS NOT NULL',
            0,
            $contextId
        );
        foreach ($groupIds as $group) {
            $allowed = $this->getGroupPerms($group, $contextId);
            foreach ($actions as $act) {
                $perms[$group][$act] = \in_array($act, $allowed);
            }
        }
        return $perms;
    }

    /**
     * @param array $actions
     * @param int $contextId
     * @return array
     */
    protected function getUserRulesForContextSave(array $actions, int $contextId): array
    {
        $perms = [];
        $userIds = $this->db->col(
            'SELECT DISTINCT userid FROM airship_perm_rules WHERE context = ? AND userid IS NOT NULL',
            0,
            $contextId
        );
        foreach ($userIds as $user) {
            $allowed = $this->getUserPerms($user, $contextId);
            foreach ($actions as $act) {
                $perms[$user][$act] = \in_array($act, $allowed);
            }
        }
        return $perms;
    }

    /**
     * @param array $postValue
     * @param array $keys
     * @return array
     */
    protected function permBoolean(array $postValue = [], array $keys = []): array
    {
        if (empty($postValue)) {
            return [];
        }
        $ret = [];
        foreach ($postValue as $idx => $sub) {
            foreach($keys as $k) {
                $ret[$idx][$k] = !empty($sub[$k]);
            }
        }
        return $ret;
    }
}