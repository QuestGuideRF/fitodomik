<?php
if (!class_exists('FitodomikInputException')) {
    class FitodomikInputException extends InvalidArgumentException
    {
    }
}
if (!class_exists('FitodomikNotFoundException')) {
    class FitodomikNotFoundException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikAuthorizationException')) {
    class FitodomikAuthorizationException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikConflictException')) {
    class FitodomikConflictException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikStorageException')) {
    class FitodomikStorageException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikUploadException')) {
    class FitodomikUploadException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikConfigurationException')) {
    class FitodomikConfigurationException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikExternalServiceException')) {
    class FitodomikExternalServiceException extends RuntimeException
    {
    }
}
if (!class_exists('FitodomikSchemaException')) {
    class FitodomikSchemaException extends RuntimeException
    {
    }
}
