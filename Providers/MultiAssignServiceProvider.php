<?php

namespace Modules\MultiAssign\Providers;

use App\Conversation;
use App\Folder;
use App\Thread;
use App\User;
use Illuminate\Support\ServiceProvider;
use Modules\MultiAssign\Entities\ConversationAssignee;

defined('MULTIASSIGN_MODULE') || define('MULTIASSIGN_MODULE', 'multiassign');

class MultiAssignServiceProvider extends ServiceProvider
{
    const ACTION_TYPE_MULTI_ASSIGN = 200;
    const PERM_MULTI_ASSIGN = 20;

    private static $showListColumn = null;

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'multiassign');

        $this->hooks();
    }

    public function register()
    {
        // Register before any boot() call translates a string. Laravel caches
        // the JSON catalogue on first use, so a path added in boot() is missed.
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    private static function showListColumn()
    {
        if (self::$showListColumn === null) {
            self::$showListColumn = (bool) \Option::get('multi_assign.show_list_column', 0);
        }
        return self::$showListColumn;
    }

    public static function getMaxAssignees()
    {
        return (int) \Option::get('multi_assign.max_assignees', 0);
    }

    public static function userCanMultiAssign($user = null)
    {
        if (!$user) {
            $user = auth()->user();
        }
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        return $user->hasPermission(self::PERM_MULTI_ASSIGN);
    }

    protected function hooks()
    {
        require __DIR__.'/../Http/routes.php';

        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(MULTIASSIGN_MODULE).'/css/multi-assign.css';
            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(MULTIASSIGN_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(MULTIASSIGN_MODULE).'/js/multi-assign.js';
            return $javascripts;
        });

        $this->registerSettingsPage();
        $this->registerPermission();
        $this->registerConvInfoDropdown();
        $this->registerEditorToolbarSelect();
        $this->registerConversationListColumn();
        $this->registerActivityLog();
        $this->registerPrimaryAssigneeSync();
        $this->registerSaveOnCreate();
        $this->registerFolderRouting();
        $this->registerNotifications();
        $this->registerCleanup();
        $this->registerMerge();
        $this->registerSearch();
        $this->registerWebhook();
        $this->registerExport();
    }

    protected function registerSettingsPage()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[MULTIASSIGN_MODULE] = [
                'title' => __('Multi Assign'),
                'icon'  => 'user',
                'order' => 620,
            ];
            return $sections;
        }, 40);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== MULTIASSIGN_MODULE) {
                return $settings;
            }
            $settings['multi_assign.show_in_assigned_folder'] = \Option::get('multi_assign.show_in_assigned_folder', 0);
            $settings['multi_assign.notify_additional_assignees'] = \Option::get('multi_assign.notify_additional_assignees', 0);
            $settings['multi_assign.show_list_column'] = \Option::get('multi_assign.show_list_column', 0);
            $settings['multi_assign.max_assignees'] = \Option::get('multi_assign.max_assignees', 0);
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== MULTIASSIGN_MODULE) {
                return $params;
            }
            return [
                'settings' => [
                    'multi_assign.show_in_assigned_folder'     => [],
                    'multi_assign.notify_additional_assignees' => [],
                    'multi_assign.show_list_column'            => [],
                    'multi_assign.max_assignees'               => [],
                ],
            ];
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== MULTIASSIGN_MODULE) {
                return $view;
            }
            return 'multiassign::settings.index';
        }, 20, 2);
    }

    protected function registerPermission()
    {
        \Eventy::addFilter('user_permissions.list', function ($list) {
            $list[] = self::PERM_MULTI_ASSIGN;
            return $list;
        });

        \Eventy::addFilter('user_permissions.name', function ($name, $permission) {
            if ($permission == self::PERM_MULTI_ASSIGN) {
                return __('Users are allowed to manage additional assignees');
            }
            return $name;
        }, 20, 2);
    }

    protected function registerEditorToolbarSelect()
    {
        \Eventy::addAction('conversation.create_form.after_subject', function ($conversation, $mailbox, $thread) {
            if (!self::userCanMultiAssign()) {
                return;
            }

            $users = $mailbox->usersAssignable();

            echo \View::make('multiassign::partials.editor_toolbar_select', [
                'users'        => $users,
                'conversation' => $conversation,
                'maxAssignees' => self::getMaxAssignees(),
            ])->render();
        }, 20, 3);
    }

    protected function registerSaveOnCreate()
    {
        \Eventy::addAction('conversation.created_by_user_can_undo', function ($conversation, $thread) {
            $multi_assign_ids = request('multi_assign_user_ids');
            if (empty($multi_assign_ids) || !is_array($multi_assign_ids)) {
                return;
            }

            $user = auth()->user();
            if (!self::userCanMultiAssign($user)) {
                return;
            }

            $max = self::getMaxAssignees();
            $count = 0;

            foreach ($multi_assign_ids as $target_user_id) {
                $target_user_id = (int) $target_user_id;
                if ($target_user_id <= 0 || $target_user_id == $conversation->user_id) {
                    continue;
                }
                if (!$conversation->mailbox->userHasAccess($target_user_id)) {
                    continue;
                }
                if ($max > 0 && $count >= $max) {
                    break;
                }

                ConversationAssignee::create([
                    'conversation_id'  => $conversation->id,
                    'user_id'          => $target_user_id,
                    'added_by_user_id' => $user ? $user->id : null,
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
                $count++;

                $target_user = User::find($target_user_id);
                if ($target_user) {
                    self::createActivityThread($conversation, $user, $target_user, 'added');
                }
            }
        }, 20, 2);
    }

    protected function registerConvInfoDropdown()
    {
        \Eventy::addAction('conversation.convinfo.before_nav', function ($conversation, $mailbox) {
            if (!self::userCanMultiAssign()) {
                return;
            }

            $assigneeIds = ConversationAssignee::getAssigneeIds($conversation->id);
            $users = $mailbox->usersAssignable();

            echo \View::make('multiassign::partials.conv_info_dropdown', [
                'conversation' => $conversation,
                'mailbox'      => $mailbox,
                'assigneeIds'  => $assigneeIds,
                'users'        => $users,
                'maxAssignees' => self::getMaxAssignees(),
            ])->render();
        }, 10, 2);
    }

    protected function registerConversationListColumn()
    {
        \Eventy::addFilter('conversations_table.preload_table_data', function ($conversations) {
            if (!self::showListColumn()) {
                return $conversations;
            }

            $conv_ids = $conversations->pluck('id')->toArray();
            if (empty($conv_ids)) {
                return $conversations;
            }

            $assignees = ConversationAssignee::whereIn('conversation_id', $conv_ids)
                ->join('users', 'users.id', '=', 'multi_assign_conversation_user.user_id')
                ->select('multi_assign_conversation_user.conversation_id', 'users.id', 'users.first_name', 'users.last_name')
                ->get()
                ->groupBy('conversation_id');

            foreach ($conversations as $conversation) {
                $conversation->multi_assignees = $assignees->get($conversation->id, collect([]));
            }

            return $conversations;
        });

        \Eventy::addAction('conversations_table.col_before_conv_number', function () {
            if (!self::showListColumn()) {
                return;
            }
            echo '<col class="conv-multi-assignee">';
        });

        \Eventy::addAction('conversations_table.th_before_conv_number', function () {
            if (!self::showListColumn()) {
                return;
            }
            echo '<th class="conv-multi-assignee"><span>'.__('Additional Assignees').'</span></th>';
        });

        \Eventy::addAction('conversations_table.td_before_conv_number', function ($conversation) {
            if (!self::showListColumn()) {
                return;
            }

            $names = '';
            if (isset($conversation->multi_assignees) && $conversation->multi_assignees->count()) {
                $names = $conversation->multi_assignees->map(function ($u) {
                    return $u->first_name.' '.$u->last_name;
                })->implode(', ');
            }

            echo '<td class="conv-multi-assignee">'
                .($names ? '<span title="'.htmlspecialchars($names).'">'.htmlspecialchars($names).'</span>' : '&nbsp;')
                .'</td>';
        }, 20, 1);
    }

    protected function registerActivityLog()
    {
        \Eventy::addFilter('thread.action_types', function ($action_types) {
            $action_types[self::ACTION_TYPE_MULTI_ASSIGN] = 'multi-assign-changed';
            return $action_types;
        });

        \Eventy::addFilter('thread.action_text', function ($did_this, $thread, $conversation_number, $escape, $viewed_by_user) {
            if ($thread->action_type != self::ACTION_TYPE_MULTI_ASSIGN) {
                return $did_this;
            }

            $data = $thread->getMeta('multi_assign_data', []);
            $action = isset($data['action']) ? $data['action'] : 'added';
            $user_name = isset($data['user_name']) ? $data['user_name'] : __('someone');

            if ($escape) {
                $user_name = htmlspecialchars($user_name);
            }

            if ($action === 'added') {
                $did_this = __(':person added :user as an additional assignee', ['user' => $user_name]);
            } else {
                $did_this = __(':person removed :user as an additional assignee', ['user' => $user_name]);
            }

            return $did_this;
        }, 20, 5);
    }

    public static function createActivityThread($conversation, $acting_user, $target_user, $action)
    {
        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $conversation->user_id;
        $thread->type = Thread::TYPE_LINEITEM;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = Thread::STATUS_NOCHANGE;
        $thread->action_type = self::ACTION_TYPE_MULTI_ASSIGN;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $acting_user->id;
        $thread->setMeta('multi_assign_data', [
            'action'    => $action,
            'user_id'   => $target_user->id,
            'user_name' => $target_user->getFullName(),
        ]);
        $thread->save();
    }

    protected function registerPrimaryAssigneeSync()
    {
        \Eventy::addAction('conversation.user_changed', function ($conversation, $user, $prev_user_id) {
            if (!$conversation->user_id || $conversation->user_id <= 0) {
                return;
            }

            $removed = ConversationAssignee::where('conversation_id', $conversation->id)
                ->where('user_id', $conversation->user_id)
                ->first();

            if ($removed) {
                $removed->delete();

                $target_user = User::find($conversation->user_id);
                if ($target_user && $user) {
                    self::createActivityThread($conversation, $user, $target_user, 'removed');
                }
            }
        }, 20, 3);
    }

    protected function registerFolderRouting()
    {
        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            if (!$user_id || !\Option::get('multi_assign.show_in_assigned_folder', 0)) {
                return $query;
            }

            if ($folder->type == Folder::TYPE_MINE) {
                $ma_sub = 'SELECT conversation_id FROM multi_assign_conversation_user WHERE user_id = ?';

                self::removeWhereColumn($query, 'user_id');

                $query->where(function ($outer) use ($user_id, $ma_sub) {
                    $outer->where('conversations.user_id', $user_id)
                          ->orWhereRaw('conversations.id IN ('.$ma_sub.')', [$user_id]);
                });
            }

            return $query;
        }, 20, 3);

        \Eventy::addFilter('folder.only_assigned_condition', function ($applied, $query, $user_id) {
            if (!$user_id || !\Option::get('multi_assign.show_in_assigned_folder', 0)) {
                return $applied;
            }

            $ma_sub = 'SELECT conversation_id FROM multi_assign_conversation_user WHERE user_id = ?';

            $query->where(function ($outer) use ($user_id, $ma_sub) {
                $outer->where('conversations.user_id', $user_id)
                      ->orWhereRaw('conversations.id IN ('.$ma_sub.')', [$user_id]);
            });

            return true;
        }, 20, 3);

        \Eventy::addFilter('folder.update_counters', function ($handled, $folder) {
            if ($handled || !\Option::get('multi_assign.show_in_assigned_folder', 0)) {
                return $handled;
            }

            if ($folder->type != Folder::TYPE_MINE || !$folder->user_id) {
                return $handled;
            }

            $ma_sub = 'SELECT conversation_id FROM multi_assign_conversation_user WHERE user_id = ?';

            $folder->active_count = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->where('status', Conversation::STATUS_ACTIVE)
                ->where(function ($q) use ($folder, $ma_sub) {
                    $q->where('user_id', $folder->user_id)
                      ->orWhereRaw('conversations.id IN ('.$ma_sub.')', [$folder->user_id]);
                })
                ->count();

            $folder->total_count = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->where(function ($q) use ($folder, $ma_sub) {
                    $q->where('user_id', $folder->user_id)
                      ->orWhereRaw('conversations.id IN ('.$ma_sub.')', [$folder->user_id]);
                })
                ->count();

            $folder->save();

            return true;
        }, 20, 2);
    }

    protected function registerNotifications()
    {
        \Eventy::addFilter('subscription.users_to_notify', function ($users_to_notify, $event_type, $events, $thread) {
            if (!\Option::get('multi_assign.notify_additional_assignees', 0)) {
                return $users_to_notify;
            }

            if (!$thread || !$thread->conversation_id) {
                return $users_to_notify;
            }

            $assignee_ids = ConversationAssignee::getAssigneeIds($thread->conversation_id);
            if (empty($assignee_ids)) {
                return $users_to_notify;
            }

            $already_notified = [];
            foreach ($users_to_notify as $medium => $users) {
                foreach ($users as $u) {
                    $already_notified[$u->id] = true;
                }
            }

            $extra_users = User::whereIn('id', $assignee_ids)->get();
            foreach ($extra_users as $extra_user) {
                if (isset($already_notified[$extra_user->id])) {
                    continue;
                }
                if (!isset($users_to_notify[1])) {
                    $users_to_notify[1] = [];
                }
                $users_to_notify[1][] = $extra_user;
                $already_notified[$extra_user->id] = true;
            }

            return $users_to_notify;
        }, 20, 4);
    }

    protected function registerCleanup()
    {
        \Eventy::addAction('conversations.before_delete_forever', function ($conversation_ids) {
            if (empty($conversation_ids)) {
                return;
            }
            ConversationAssignee::whereIn('conversation_id', $conversation_ids)->delete();
        }, 20, 1);

        \Eventy::addAction('user.deleted', function ($user) {
            ConversationAssignee::where('user_id', $user->id)->delete();
        }, 20, 1);
    }

    protected function registerMerge()
    {
        \Eventy::addAction('conversation.merged', function ($target_conversation, $merged_conversation, $user) {
            $target_ids = ConversationAssignee::getAssigneeIds($target_conversation->id);
            $merged_ids = ConversationAssignee::getAssigneeIds($merged_conversation->id);

            $to_add = array_diff($merged_ids, $target_ids);

            if ($target_conversation->user_id) {
                $to_add = array_diff($to_add, [$target_conversation->user_id]);
            }

            foreach ($to_add as $user_id) {
                ConversationAssignee::create([
                    'conversation_id'  => $target_conversation->id,
                    'user_id'          => $user_id,
                    'added_by_user_id' => $user ? $user->id : null,
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
            }
        }, 20, 3);
    }

    protected function registerSearch()
    {
        \Eventy::addFilter('search.filters_list', function ($filters_list) {
            $filters_list[] = 'additional_assignee';
            return $filters_list;
        });

        \Eventy::addFilter('search.conversations.apply_filters', function ($query, $filters, $q) {
            if (!empty($filters['additional_assignee'])) {
                $ma_sub = 'SELECT conversation_id FROM multi_assign_conversation_user WHERE user_id = ?';
                $query->whereRaw('conversations.id IN ('.$ma_sub.')', [(int) $filters['additional_assignee']]);
            }
            return $query;
        }, 20, 3);
    }

    protected function registerWebhook()
    {
        if (!\Module::isActive('apiwebhooks')) {
            return;
        }

        \Eventy::addFilter('webhooks.events', function ($events) {
            $events[] = 'convo.additional_assignee.changed';
            return $events;
        });
    }

    public static function fireWebhookEvent($conversation)
    {
        if (\Module::isActive('apiwebhooks')) {
            \Modules\ApiWebhooks\Providers\ApiWebhooksServiceProvider::maybeRunWebhook(
                'convo.additional_assignee.changed',
                $conversation
            );
        }
    }

    protected function registerExport()
    {
        \Eventy::addFilter('exportconversations.exportable_fields', function ($fields) {
            $fields['ec_multi_assign'] = __('Additional Assignees');
            return $fields;
        });
    }

    private static function removeWhereColumn($query, $column)
    {
        $base = $query->getQuery();
        $old_wheres = $base->wheres;
        $old_bindings = $base->bindings['where'];

        $base->wheres = [];
        $base->bindings['where'] = [];

        $binding_idx = 0;
        foreach ($old_wheres as $w) {
            $consumes = 0;
            if ($w['type'] === 'Basic' && isset($w['value'])) {
                $consumes = 1;
            } elseif ($w['type'] === 'In' || $w['type'] === 'NotIn') {
                $consumes = count($w['values']);
            } elseif ($w['type'] === 'between') {
                $consumes = 2;
            }

            $is_target = ($w['type'] === 'Basic' && $w['column'] === $column);

            if (!$is_target) {
                $base->wheres[] = $w;
                for ($i = 0; $i < $consumes; $i++) {
                    $base->bindings['where'][] = $old_bindings[$binding_idx + $i];
                }
            }

            $binding_idx += $consumes;
        }
    }
}
