<?php

it('responds on the health check endpoint', function () {
    $this->get('/up')->assertOk();
});
