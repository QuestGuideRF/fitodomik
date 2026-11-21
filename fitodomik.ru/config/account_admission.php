<?php
function fitodomikAccountAdmitted($user): bool
{
    return is_array($user) && array_key_exists('is_locked', $user)
        && in_array($user['is_locked'], [0, '0', false], true);
}
