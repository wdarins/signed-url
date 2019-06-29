<?php

namespace Linkeys\LinkGenerator\Tests\Integration\Middleware;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Linkeys\LinkGenerator\Events\LinkClicked;
use Linkeys\LinkGenerator\Exceptions\ClickLimit\LinkGroupClickLimitReachedException;
use Linkeys\LinkGenerator\Exceptions\Expiry\LinkExpiredException;
use Linkeys\LinkGenerator\Exceptions\Expiry\LinkGroupExpiredException;
use Linkeys\LinkGenerator\Models\Link;
use Linkeys\LinkGenerator\Exceptions\ClickLimit\LinkClickLimitReachedException;
use Linkeys\LinkGenerator\Middleware\CheckLinkValid;
use Linkeys\LinkGenerator\Models\Group;
use Linkeys\LinkGenerator\Support\LinkRepository\EloquentLinkRepository;
use Linkeys\LinkGenerator\Support\LinkRepository\LinkRepository;
use Linkeys\LinkGenerator\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CheckLinkValidTest extends TestCase
{

    public function saveLinkInRequest($link){
        $request = $this->prophesize(Request::class);
        $request->get('link')->shouldBeCalled()->willReturn($link);
        return $request;
    }

    /** @test */
    public function it_throws_a_link_click_limit_reached_exception_if_the_link_clicks_is_the_same_as_the_click_limit(){
        $link = factory(Link::class)->create(['click_limit' => 5, 'clicks' => 5]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkClickLimitReachedException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }

    /** @test */
    public function it_throws_a_link_group_click_limit_reached_exception_if_the_link_group_has_been_triggered_too_many_times(){
        $group = factory(Group::class)->create(['click_limit' => 5]);
        $link = factory(Link::class)->create(['clicks' => 2, 'group_id'=>$group->id]);
        factory(Link::class)->create(['clicks' => 2, 'group_id'=>$group->id]);
        factory(Link::class)->create(['clicks' => 2, 'group_id'=>$group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkGroupClickLimitReachedException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }


    /** @test */
    public function it_throws_a_link_expired_exception_if_the_link_expiry_is_in_the_past(){
        $link = factory(Link::class)->create(['expiry' => Carbon::now()->subMinute()]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkExpiredException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }

    /** @test */
    public function it_throws_a_link_expired_exception_if_the_link_expiry_is_in_the_past_and_group_expiry_is_in_the_past(){
        $group = factory(Group::class)->create(['expiry' => Carbon::now()->subMinute()]);
        $link = factory(Link::class)->create(['expiry' => Carbon::now()->subMinute(), 'group_id' => $group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkExpiredException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }

    /** @test */
    public function it_throws_a_link_expired_exception_if_the_link_expiry_is_in_the_past_and_group_is_not_expired(){
        $group = factory(Group::class)->create(['expiry' => Carbon::now()->addMinute()]);
        $link = factory(Link::class)->create(['expiry' => Carbon::now()->subMinute(), 'group_id' => $group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkExpiredException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }

    /** @test */
    public function it_throws_a_link_group_expired_exception_if_the_link_expiry_is_null_and_the_group_expired(){
        $group = factory(Group::class)->create(['expiry' => Carbon::now()->subMinute()]);
        $link = factory(Link::class)->create(['expiry' => null, 'group_id' => $group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $this->expectException(LinkGroupExpiredException::class);

        $linkValidMiddleware->handle($request->reveal(), function(){});
    }

    /** @test */
    public function it_passes_if_the_link_expiry_is_not_null_but_valid_and_the_group_expired()
    {
        $group = factory(Group::class)->create(['expiry' => Carbon::now()->subMinute()]);
        $link = factory(Link::class)->create(['expiry' => Carbon::now()->addMinute(), 'group_id' => $group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));

        $called = false;
        try {
            $linkValidMiddleware->handle($request->reveal(), function() use (&$called) {
                $called = true;
            });
        } catch(\Exception $e) {
            $this->assertTrue(false, 'Link Validation Middleware did not pass');
        }

        $this->assertTrue($called, 'Callback wasn\'t called');
    }


    /** @test */
    public function it_passes_if_the_link_is_not_expired_and_the_group_if_not_expired(){
        $group = factory(Group::class)->create(['expiry' => Carbon::now()->addMinute()]);
        $link = factory(Link::class)->create(['expiry' => Carbon::now()->addMinute(), 'group_id' => $group->id]);
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));

        $called = false;
        try {
            $linkValidMiddleware->handle($request->reveal(), function() use (&$called) {
                $called = true;
            });
        } catch(\Exception $e) {
            $this->assertTrue(false, 'Link Validation Middleware did not pass');
        }

        $this->assertTrue($called, 'Callback wasn\'t called');
    }

    /** @test */
    public function it_fires_a_single_link_clicked_event_if_the_middleware_passes(){
        $link = factory(Link::class)->create();
        $request = $this->saveLinkInRequest($link);

        Event::fake();
        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));
        $linkValidMiddleware->handle($request->reveal(), function(){});
        Event::assertDispatched(LinkClicked::class, 1);

    }

    /** @test */
    public function it_passes_a_link_to_the_link_clicked_event(){
        $link = factory(Link::class)->create();
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));

        Event::fake();
        $linkValidMiddleware->handle($request->reveal(), function(){});
        Event::assertDispatched(LinkClicked::class, function($event) use ($link){
            return $link->is($event->link);
        });
    }

    /** @test */
    public function it_calls_the_callback_if_the_link_is_valid(){

        $link = factory(Link::class)->create();
        $request = $this->saveLinkInRequest($link);

        $linkValidMiddleware = new CheckLinkValid(new EloquentLinkRepository(new Link));

        $called = false;
        $linkValidMiddleware->handle($request->reveal(), function() use (&$called) {
            $called = true;
        });

        $this->assertTrue($called, 'Callback wasn\'t called');

    }


}