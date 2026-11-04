<li>
    <div class="btn-group" id="conv-multi-assignee" data-toggle="tooltip" data-max="{{ $maxAssignees }}" data-lang-none="{{ __('None') }}" data-lang-assignees="{{ __('assignees') }}" title="{{ __('Additional Assignees') }}: {{ count($assigneeIds) ? implode(', ', $users->whereIn('id', $assigneeIds)->pluck('first_name')->toArray()) : __('None') }}">
        <button type="button" class="btn btn-default conv-info-icon" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-title="{{ __('Additional Assignees') }}" aria-hidden="true">
            <i class="glyphicon glyphicon-user"></i><small class="glyphicon glyphicon-plus multi-assign-plus-icon"></small>
        </button>
        <button type="button" class="btn btn-default dropdown-toggle conv-info-val" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-title="{{ __('Additional Assignees') }}">
            <span class="multi-assign-label">
                @if (count($assigneeIds))
                    @php
                        $names = $users->whereIn('id', $assigneeIds)->map(function($u) {
                            return $u->getFullName();
                        })->toArray();
                    @endphp
                    @if (count($names) > 3)
                        {{ count($names) }} {{ __('assignees') }}
                    @else
                        {{ implode(', ', $names) }}
                    @endif
                @else
                    {{ __('None') }}
                @endif
            </span>
            <span class="caret"></span>
        </button>
        <ul class="dropdown-menu dm-scrollable multi-assign-dropdown" data-conversation-id="{{ $conversation->id }}">
            @if (count($assigneeIds))
                <li class="multi-assign-clear-item"><a href="#" class="multi-assign-clear-all"><i class="glyphicon glyphicon-remove"></i> {{ __('Clear all') }}</a></li>
                <li class="divider"></li>
            @endif
            @foreach ($users as $u)
                @if ($u->id != $conversation->user_id)
                    <li>
                        <a href="#" class="multi-assign-toggle" data-user_id="{{ $u->id }}">
                            <i class="glyphicon {{ in_array($u->id, $assigneeIds) ? 'glyphicon-check' : 'glyphicon-unchecked' }}"></i>
                            {{ $u->getFullName() }}
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
</li>
