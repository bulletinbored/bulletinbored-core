<?php

/**
 * Errors.php — typed HTTP error exceptions.
 *
 * Throw these from handlers/services to signal a specific HTTP error.
 * The router (or a top-level catch) converts them into a Response.
 *
 * Usage:
 *   throw new ForbiddenException();
 *   throw new NotFoundException('Thread not found');
 *   throw new ValidationException(['email' => 'Invalid email']);
 */

namespace Bulletin;

class HttpException extends \RuntimeException
{
    protected int $statusCode;
    protected array $details;

    public function __construct(string $message = '', int $statusCode = 500, array $details = [], \Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function toResponse(): Response
    {
        return Response::error($this->statusCode, $this->getMessage());
    }
}

class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message, 401);
    }
}

class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403);
    }
}

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404);
    }
}

class ValidationException extends HttpException
{
    public function __construct(array $errors = [], string $message = 'Validation failed')
    {
        parent::__construct($message, 422, $errors);
    }

    public function toResponse(): Response
    {
        return Response::json(['error' => $this->getMessage(), 'fields' => $this->details], 422);
    }
}

class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict')
    {
        parent::__construct($message, 409);
    }
}

class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too many requests')
    {
        parent::__construct($message, 429);
    }
}
