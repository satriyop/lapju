<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private OfficeLevel $mabesLevel;

    private OfficeLevel $kodamLevel;

    private OfficeLevel $kodimLevel;

    private OfficeLevel $koramilLevel;

    protected function setUp(): void
    {
        parent::setUp();

        // Create 4-level military hierarchy
        $this->mabesLevel = OfficeLevel::factory()->create(['level' => 1, 'name' => 'Mabes']);
        $this->kodamLevel = OfficeLevel::factory()->create(['level' => 2, 'name' => 'Kodam']);
        $this->kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $this->koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
    }

    public function test_office_can_be_created_with_nested_set_values(): void
    {
        $office = Office::create([
            'name' => 'Kodim 0601',
            'code' => 'KDM-0601',
            'level_id' => $this->kodimLevel->id,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertDatabaseHas('offices', [
            'id' => $office->id,
            'name' => 'Kodim 0601',
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_office_parent_child_relationship(): void
    {
        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $this->assertEquals($kodim->id, $koramil->parent_id);
        $this->assertInstanceOf(Office::class, $koramil->parent);
        $this->assertEquals($kodim->id, $koramil->parent->id);
    }

    public function test_office_can_have_multiple_children(): void
    {
        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $koramil1 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 4,
            '_rgt' => 5,
        ]);

        $koramil3 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 6,
            '_rgt' => 7,
        ]);

        $this->assertCount(3, $kodim->children);
        $this->assertTrue($kodim->children->contains($koramil1));
        $this->assertTrue($kodim->children->contains($koramil2));
        $this->assertTrue($kodim->children->contains($koramil3));
    }

    public function test_descendants_method_returns_all_nested_children(): void
    {
        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            '_lft' => 1,
            '_rgt' => 10,
        ]);

        $koramil1 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 2,
            '_rgt' => 5,
        ]);

        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 6,
            '_rgt' => 9,
        ]);

        // Create sub-offices under koramil2
        $subOffice = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $koramil2->id,
            '_lft' => 7,
            '_rgt' => 8,
        ]);

        $descendants = $kodim->descendants();

        $this->assertCount(3, $descendants);
        $this->assertTrue($descendants->contains($koramil1));
        $this->assertTrue($descendants->contains($koramil2));
        $this->assertTrue($descendants->contains($subOffice));
    }

    public function test_ancestors_method_returns_all_parents(): void
    {
        $mabes = Office::factory()->create([
            'level_id' => $this->mabesLevel->id,
            '_lft' => 1,
            '_rgt' => 10,
        ]);

        $kodam = Office::factory()->create([
            'level_id' => $this->kodamLevel->id,
            'parent_id' => $mabes->id,
            '_lft' => 2,
            '_rgt' => 9,
        ]);

        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'parent_id' => $kodam->id,
            '_lft' => 3,
            '_rgt' => 8,
        ]);

        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 4,
            '_rgt' => 7,
        ]);

        $ancestors = $koramil->ancestors();

        $this->assertCount(3, $ancestors);
        $this->assertTrue($ancestors->contains($kodim));
        $this->assertTrue($ancestors->contains($kodam));
        $this->assertTrue($ancestors->contains($mabes));
    }

    public function test_get_hierarchy_path_returns_breadcrumb_trail(): void
    {
        $kodam = Office::factory()->create([
            'name' => 'Kodam Jaya',
            'level_id' => $this->kodamLevel->id,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $kodim = Office::factory()->create([
            'name' => 'Kodim 0501',
            'level_id' => $this->kodimLevel->id,
            'parent_id' => $kodam->id,
            '_lft' => 2,
            '_rgt' => 7,
        ]);

        $koramil = Office::factory()->create([
            'name' => 'Koramil 01',
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 3,
            '_rgt' => 6,
        ]);

        $path = $koramil->getHierarchyPath();

        $this->assertEquals('Kodam Jaya > Kodim 0501 > Koramil 01', $path);
    }

    public function test_get_hierarchy_path_for_root_office(): void
    {
        $root = Office::factory()->create([
            'name' => 'Mabes TNI',
            'level_id' => $this->mabesLevel->id,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $path = $root->getHierarchyPath();

        $this->assertEquals('Mabes TNI', $path);
    }

    public function test_nested_set_boundaries_are_correct(): void
    {
        $parent = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            '_lft' => 1,
            '_rgt' => 6,
        ]);

        $child = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 5,
        ]);

        $grandchild = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $child->id,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        // Child boundaries should be within parent
        $this->assertTrue($child->_lft > $parent->_lft);
        $this->assertTrue($child->_rgt < $parent->_rgt);

        // Grandchild boundaries should be within child
        $this->assertTrue($grandchild->_lft > $child->_lft);
        $this->assertTrue($grandchild->_rgt < $child->_rgt);
    }

    public function test_office_belongs_to_level(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
        ]);

        $this->assertInstanceOf(OfficeLevel::class, $office->level);
        $this->assertEquals($this->kodimLevel->id, $office->level->id);
        $this->assertEquals('Kodim', $office->level->name);
    }

    public function test_office_has_many_users(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
        ]);

        $user1 = User::factory()->create(['office_id' => $office->id]);
        $user2 = User::factory()->create(['office_id' => $office->id]);

        $this->assertCount(2, $office->users);
        $this->assertTrue($office->users->contains($user1));
        $this->assertTrue($office->users->contains($user2));
    }

    public function test_office_has_many_projects(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
        ]);

        $project1 = Project::factory()->create(['office_id' => $office->id]);
        $project2 = Project::factory()->create(['office_id' => $office->id]);

        $this->assertCount(2, $office->projects);
        $this->assertTrue($office->projects->contains($project1));
        $this->assertTrue($office->projects->contains($project2));
    }

    public function test_office_code_is_stored(): void
    {
        $office = Office::factory()->create([
            'name' => 'Kodim 0601',
            'code' => 'KDM-0601',
            'level_id' => $this->kodimLevel->id,
        ]);

        $this->assertEquals('KDM-0601', $office->code);
    }

    public function test_office_coverage_areas_are_stored(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'coverage_province' => 'Jawa Barat',
            'coverage_city' => 'Bandung',
            'coverage_district' => 'Cidadap',
        ]);

        $this->assertEquals('Jawa Barat', $office->coverage_province);
        $this->assertEquals('Bandung', $office->coverage_city);
        $this->assertEquals('Cidadap', $office->coverage_district);
    }

    public function test_office_notes_are_optional(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'notes' => null,
        ]);

        $this->assertNull($office->notes);
    }

    public function test_office_can_be_updated(): void
    {
        $office = Office::factory()->create([
            'name' => 'Old Name',
            'level_id' => $this->kodimLevel->id,
        ]);

        $office->update(['name' => 'New Name']);

        $this->assertEquals('New Name', $office->fresh()->name);
    }

    public function test_office_can_be_deleted(): void
    {
        $office = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
        ]);

        $officeId = $office->id;
        $office->delete();

        $this->assertDatabaseMissing('offices', ['id' => $officeId]);
    }

    public function test_descendants_ordered_by_lft(): void
    {
        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $koramil1 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 4,
            '_rgt' => 5,
        ]);

        $koramil3 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim->id,
            '_lft' => 6,
            '_rgt' => 7,
        ]);

        $descendants = $kodim->descendants();

        $this->assertEquals($koramil1->id, $descendants[0]->id);
        $this->assertEquals($koramil2->id, $descendants[1]->id);
        $this->assertEquals($koramil3->id, $descendants[2]->id);
    }

    public function test_ancestors_ordered_by_lft(): void
    {
        $mabes = Office::factory()->create([
            'level_id' => $this->mabesLevel->id,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $kodam = Office::factory()->create([
            'level_id' => $this->kodamLevel->id,
            'parent_id' => $mabes->id,
            '_lft' => 2,
            '_rgt' => 7,
        ]);

        $kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'parent_id' => $kodam->id,
            '_lft' => 3,
            '_rgt' => 6,
        ]);

        $ancestors = $kodim->ancestors();

        $this->assertEquals($mabes->id, $ancestors[0]->id);
        $this->assertEquals($kodam->id, $ancestors[1]->id);
    }

    public function test_office_without_parent_has_null_parent_id(): void
    {
        $root = Office::factory()->create([
            'level_id' => $this->mabesLevel->id,
            'parent_id' => null,
        ]);

        $this->assertNull($root->parent_id);
        $this->assertNull($root->parent);
    }

    public function test_office_with_no_descendants_returns_empty_collection(): void
    {
        $leaf = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $descendants = $leaf->descendants();

        $this->assertCount(0, $descendants);
    }

    public function test_office_with_no_ancestors_returns_empty_collection(): void
    {
        $root = Office::factory()->create([
            'level_id' => $this->mabesLevel->id,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $ancestors = $root->ancestors();

        $this->assertCount(0, $ancestors);
    }
}
