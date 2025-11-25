<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Location;
use App\Models\Partner;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationPartnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_can_be_created_with_required_fields(): void
    {
        $location = Location::create([
            'village_name' => 'Desa Maju',
            'district_name' => 'Kecamatan Sejahtera',
            'city_name' => 'Bandung',
            'province_name' => 'Jawa Barat',
        ]);

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
            'village_name' => 'Desa Maju',
            'city_name' => 'Bandung',
        ]);
    }

    public function test_location_can_be_created_with_optional_notes(): void
    {
        $location = Location::create([
            'village_name' => 'Desa Sentosa',
            'district_name' => 'Kecamatan Makmur',
            'city_name' => 'Jakarta',
            'province_name' => 'DKI Jakarta',
            'notes' => 'Area strategis dekat pusat kota',
        ]);

        $this->assertEquals('Area strategis dekat pusat kota', $location->notes);
    }

    public function test_location_has_many_projects_relationship(): void
    {
        $location = Location::factory()->create();
        $project1 = Project::factory()->create(['location_id' => $location->id]);
        $project2 = Project::factory()->create(['location_id' => $location->id]);

        $this->assertTrue($location->projects()->exists());
        $this->assertGreaterThanOrEqual(2, $location->projects()->count());
        $this->assertTrue($location->projects->contains($project1));
        $this->assertTrue($location->projects->contains($project2));
    }

    public function test_location_can_be_updated(): void
    {
        $location = Location::factory()->create([
            'village_name' => 'Old Village',
            'city_name' => 'Old City',
        ]);

        $location->update([
            'village_name' => 'New Village',
            'city_name' => 'New City',
        ]);

        $this->assertEquals('New Village', $location->fresh()->village_name);
        $this->assertEquals('New City', $location->fresh()->city_name);
    }

    public function test_location_can_be_deleted(): void
    {
        $location = Location::factory()->create();
        $locationId = $location->id;

        $location->delete();

        $this->assertDatabaseMissing('locations', [
            'id' => $locationId,
        ]);
    }

    public function test_location_notes_are_optional(): void
    {
        $location = Location::create([
            'village_name' => 'Desa Tanpa Catatan',
            'district_name' => 'Kecamatan A',
            'city_name' => 'Kota B',
            'province_name' => 'Provinsi C',
        ]);

        $this->assertNull($location->notes);
    }

    public function test_location_stores_full_address_hierarchy(): void
    {
        $location = Location::create([
            'village_name' => 'Desa Sukamaju',
            'district_name' => 'Kecamatan Cibeunying',
            'city_name' => 'Bandung',
            'province_name' => 'Jawa Barat',
        ]);

        $this->assertEquals('Desa Sukamaju', $location->village_name);
        $this->assertEquals('Kecamatan Cibeunying', $location->district_name);
        $this->assertEquals('Bandung', $location->city_name);
        $this->assertEquals('Jawa Barat', $location->province_name);
    }

    public function test_partner_can_be_created_with_required_fields(): void
    {
        $partner = Partner::create([
            'name' => 'PT Mitra Sejahtera',
            'description' => 'Perusahaan kontraktor berpengalaman',
            'address' => 'Jl. Merdeka No. 123, Jakarta',
        ]);

        $this->assertDatabaseHas('partners', [
            'id' => $partner->id,
            'name' => 'PT Mitra Sejahtera',
        ]);
    }

    public function test_partner_can_be_created_without_optional_description(): void
    {
        $partner = Partner::create([
            'name' => 'PT Simple Partner',
            'address' => 'Jl. Sederhana No. 1',
        ]);

        $this->assertNull($partner->description);
        $this->assertNotNull($partner->address);
    }

    public function test_partner_has_many_projects_relationship(): void
    {
        $partner = Partner::factory()->create();
        $project1 = Project::factory()->create(['partner_id' => $partner->id]);
        $project2 = Project::factory()->create(['partner_id' => $partner->id]);

        $this->assertTrue($partner->projects()->exists());
        $this->assertGreaterThanOrEqual(2, $partner->projects()->count());
        $this->assertTrue($partner->projects->contains($project1));
        $this->assertTrue($partner->projects->contains($project2));
    }

    public function test_partner_can_be_updated(): void
    {
        $partner = Partner::factory()->create([
            'name' => 'Old Partner Name',
            'description' => 'Old description',
        ]);

        $partner->update([
            'name' => 'New Partner Name',
            'description' => 'New description',
        ]);

        $this->assertEquals('New Partner Name', $partner->fresh()->name);
        $this->assertEquals('New description', $partner->fresh()->description);
    }

    public function test_partner_can_be_deleted(): void
    {
        $partner = Partner::factory()->create();
        $partnerId = $partner->id;

        $partner->delete();

        $this->assertDatabaseMissing('partners', [
            'id' => $partnerId,
        ]);
    }

    public function test_partner_description_can_be_long_text(): void
    {
        $longDescription = str_repeat('Lorem ipsum dolor sit amet, ', 100);

        $partner = Partner::create([
            'name' => 'PT Long Description',
            'description' => $longDescription,
            'address' => 'Jl. Standar No. 1',
        ]);

        $this->assertEquals($longDescription, $partner->description);
    }

    public function test_partner_address_can_be_long_text(): void
    {
        $longAddress = 'Jl. Sangat Panjang Sekali No. 999, ' . str_repeat('RT 001/RW 002, ', 20);

        $partner = Partner::create([
            'name' => 'PT Long Address',
            'address' => $longAddress,
        ]);

        $this->assertEquals($longAddress, $partner->address);
    }

    public function test_location_and_partner_can_be_used_together_in_project(): void
    {
        $location = Location::factory()->create();
        $partner = Partner::factory()->create();

        $project = Project::factory()->create([
            'location_id' => $location->id,
            'partner_id' => $partner->id,
        ]);

        $this->assertEquals($location->id, $project->location_id);
        $this->assertEquals($partner->id, $project->partner_id);
        $this->assertInstanceOf(Location::class, $project->location);
        $this->assertInstanceOf(Partner::class, $project->partner);
    }

    public function test_multiple_projects_can_share_same_location(): void
    {
        $location = Location::factory()->create();
        $partner1 = Partner::factory()->create();
        $partner2 = Partner::factory()->create();

        $project1 = Project::factory()->create([
            'location_id' => $location->id,
            'partner_id' => $partner1->id,
        ]);

        $project2 = Project::factory()->create([
            'location_id' => $location->id,
            'partner_id' => $partner2->id,
        ]);

        $locationProjects = $location->projects;
        $this->assertGreaterThanOrEqual(2, $locationProjects->count());
        $this->assertTrue($locationProjects->contains($project1));
        $this->assertTrue($locationProjects->contains($project2));
    }

    public function test_multiple_projects_can_share_same_partner(): void
    {
        $partner = Partner::factory()->create();
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();

        $project1 = Project::factory()->create([
            'partner_id' => $partner->id,
            'location_id' => $location1->id,
        ]);

        $project2 = Project::factory()->create([
            'partner_id' => $partner->id,
            'location_id' => $location2->id,
        ]);

        $partnerProjects = $partner->projects;
        $this->assertGreaterThanOrEqual(2, $partnerProjects->count());
        $this->assertTrue($partnerProjects->contains($project1));
        $this->assertTrue($partnerProjects->contains($project2));
    }

    public function test_location_timestamps_are_set_automatically(): void
    {
        $location = Location::create([
            'village_name' => 'Desa Test',
            'district_name' => 'Kecamatan Test',
            'city_name' => 'Kota Test',
            'province_name' => 'Provinsi Test',
        ]);

        $this->assertNotNull($location->created_at);
        $this->assertNotNull($location->updated_at);
    }

    public function test_partner_timestamps_are_set_automatically(): void
    {
        $partner = Partner::create([
            'name' => 'PT Test Timestamps',
            'address' => 'Jl. Waktu No. 1',
        ]);

        $this->assertNotNull($partner->created_at);
        $this->assertNotNull($partner->updated_at);
    }
}
