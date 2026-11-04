<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Additional Assignees') }}</label>
    <div class="col-sm-9">
        <div class="btn-group multi-assign-create-group" id="multi-assign-create" data-max="{{ $maxAssignees }}" data-lang-none="{{ __('None') }}" data-lang-assignees="{{ __('assignees') }}" data-lang-max="{{ __('Maximum of :max additional assignees') }}">
            <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                <span class="multi-assign-create-label">{{ __('None') }}</span>
                <span class="caret"></span>
            </button>
            <ul class="dropdown-menu dm-scrollable multi-assign-create-dropdown">
                <li class="multi-assign-clear-item" style="display:none;"><a href="#" class="multi-assign-create-clear-all"><i class="glyphicon glyphicon-remove"></i> {{ __('Clear all') }}</a></li>
                <li class="divider multi-assign-clear-divider" style="display:none;"></li>
                @foreach ($users as $u)
                    <li>
                        <a href="#" class="multi-assign-create-toggle" data-user_id="{{ $u->id }}" data-user_name="{{ $u->getFullName() }}">
                            <i class="glyphicon glyphicon-unchecked"></i>
                            {{ $u->getFullName() }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        <div id="multi-assign-create-inputs"></div>
    </div>
</div>
